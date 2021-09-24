<?php
namespace Windcave\Payments\Controller\PxFusion;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Registry;
use Magento\Sales\Model\Order\ShipmentFactory;
use Magento\Sales\Model\Order\Invoice;

use Windcave\Payments\Helper\FileLock;

abstract class CommonAction extends \Magento\Framework\App\Action\Action
{
    const STATUS_AUTHORIZED = 'windcave_authorized';
    const STATUS_FAILED = 'windcave_failed';

    // Transaction approved.
    const APPROVED = 0;
    
    // Transaction declined.
    const DECLINED = 1;
    
    // Transaction declined due to transient error (retry advised).
    const TRANSIENT_ERROR = 2;
    
    // Invalid data submitted in form post (alert site admin).
    const INVALID_DATA = 3;
    
    // Transaction result cannot be determined at this time (re-run GetTransaction).
    const RESULT_UNKOWN = 4;
    
    // Transaction did not proceed due to being attempted after timeout timestamp or having been cancelled by a CancelTransaction call.
    const CANCELLED = 5;
    
    // No transaction found (SessionId query failed to return a transaction record transaction not yet attempted).
    const NO_TRANSACTION = 6;

    const MAX_RETRY_COUNT = 10;

    /**
     *
     * @var \Magento\Checkout\Model\Session
     */
    private $_checkoutSession;

    /**
     *
     * @var \Windcave\Payments\Helper\PxFusion\Communication
     */
    private $_communication;

    /**
     *
     * @var \Windcave\Payments\Helper\PxFusion\Configuration
     */
    private $_configuration;

    /**
     *
     * @var \Magento\Framework\Message\ManagerInterface
     */
    private $_messageManager;

    /**
     *
     * @var \Windcave\Payments\Logger\DpsLogger
     */
    private $_logger;

    /**
     *
     * @var \Magento\Quote\Model\QuoteFactory
     */
    private $_quoteFactory;


    /**
     *
     * @var \Magento\Sales\Model\Order\Status\HistoryFactory
     */
    private $_orderHistoryFactory;

    /**
     * @var \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface
     */
    private $_transactionBuilder;

    /**
     * @var \Magento\Framework\Api\SearchCriteriaBuilder
     */
    private $_searchCriteriaBuilder;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface;
     */
    private $_orderRepository;

    /**
     *
     * @var \Magento\Sales\Model\Order\Email\Sender\OrderSender
     */
    private $_orderSender;

    /**
     * @var \Magento\Framework\Notification\NotifierInterface
     */
    private $_notifierInterface;

    /**
     * @var \Magento\Framework\Serialize\Serializer\Json
     */
    private $_json;

    /**
     * @var \Magento\Framework\Serialize\Serializer\Serialize
     */
    private $_serialize;

    public function __construct(
        Context $context,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Sales\Model\Order\Status\HistoryFactory $orderHistoryFactory,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $txnBuilder,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Framework\Notification\NotifierInterface $notifierInterface
    ) {
        parent::__construct($context);

        $this->_logger = $this->_objectManager->get("\Windcave\Payments\Logger\DpsLogger");
        $this->_communication = $this->_objectManager->get("\Windcave\Payments\Helper\PxFusion\Communication");
        $this->_configuration = $this->_objectManager->get("\Windcave\Payments\Helper\PxFusion\Configuration");

        $this->_json = $this->_objectManager->get(\Magento\Framework\Serialize\Serializer\Json::class);
        $this->_serialize = $this->_objectManager->get(\Magento\Framework\Serialize\Serializer\Serialize::class);

        $this->_messageManager = $context->getMessageManager();

        $this->_checkoutSession = $checkoutSession;
        $this->_quoteFactory = $quoteFactory;
        $this->_transactionBuilder = $txnBuilder;
        $this->_searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->_orderRepository = $orderRepository;
        $this->_orderSender = $orderSender;

        $this->_orderHistoryFactory = $orderHistoryFactory;
        $this->_notifierInterface = $notifierInterface;

        $this->_logger->info(__METHOD__);
    }

    public function handlePaymentResponse()
    {
        $token = $this->getRequest()->getParam('sessionid');
        $userName = $this->_configuration->getUserName();
        $this->_logger->info(__METHOD__ . " userName:{$userName} token:{$token}");

        /**
         *
         * @var Windcave\Payments\Helper\FileLock;
         */
        $lockHandler = null;
        try {
            $lockFolder = $this->_configuration->getLocksFolder();
            if (empty($lockFolder)) {
                $lockFolder = BP . "/var/locks";
            }

            $lockHandler = new FileLock($token, $lockFolder);
            if (!$lockHandler->tryLock(false)) {
                $action = $this->getRequest()->getActionName();
                $params = $this->getRequest()->getParams();
                $triedTime = 0;
                if (array_key_exists('TriedTime', $params)) {
                    $triedTime = $params['TriedTime'];
                }
                if ($triedTime > 40) { // 40 seconds should be enough
                    $this->_redirectToCartPageWithError("Failed to process the order, please contact support.");
                    $this->_logger->critical(__METHOD__ . " lock timeout. userName:{$userName} token:{$token} triedTime:{$triedTime}");
                    return;
                }
                
                $params['TriedTime'] = $triedTime + 1;
                
                $this->_logger->info(__METHOD__ . " redirecting to self, wait for lock release. userName:{$userName} token:{$token} triedTime:{$triedTime}");
                sleep(1); // wait for sometime about lock release
                return $this->_forward($action, null, null, $params);
            }
            
            $this->_handlePaymentResponseWithoutLock2($userName, $token);
            $lockHandler->release();
        } catch (\Exception $e) {
            if (isset($lockHandler)) {
                $lockHandler->release();
            }
            
            $this->_notifierInterface->addMajor(
                "Failed to process PxFusion response.",
                "SessionId: " . $token . ". See Windcave extension log for more details."
            );

            $this->_logger->critical(__METHOD__ . "  " . "\n" . $e->getMessage() . $e->getTraceAsString());
            $this->_redirectToCartPageWithError("Failed to processing the order, please contact support.");
        }
    }

    /**
     * @return \Magento\Sales\Model\Order
     */
    private function _getOrderByIncrementId($incrementId)
    {
        $searchCriteria = $this->_searchCriteriaBuilder->addFilter('increment_id', $incrementId, 'eq')->create();
        $orderList = $this->_orderRepository->getList($searchCriteria)->getItems();
        if (count($orderList) == 0) {
            $this->_logger->error(__METHOD__ . " Unable to load order with incrementId:{$incrementId}");
            throw new \Exception("Unable to load order with incrementId={$incrementId}");
        }

        return reset($orderList);
    }

    private function _findTransactionResultField($result, $fieldName)
    {
        if (!isset($result['transactionResultFields']) || !isset($result['transactionResultFields']->transactionResultField)) {
            return null;
        }

        foreach ($result['transactionResultFields']->transactionResultField as $value) {
            if (!isset($value->fieldName)) {
                continue;
            }

            if ($value->fieldName != $fieldName) {
                continue;
            }

            if (!isset($value->fieldValue)) {
                return null;
            }

            return $value->fieldValue;
        }

        return null;
    }

    private function _handlePaymentResponseWithoutLock2($userName, $token)
    {
        // This function is for handling the response for the case when we place the order prior to redirecting to PxPay.

        $this->_logger->info(__METHOD__ . " userName:{$userName} token:{$token}");
        
        // There will arrive two responses in general - one is from user redirect, another one is FPRN.
        // We need to do all the processing logic only once. So if there is already txn status in DB,
        // just redirecting to WaitForResult.

        // No transaction status means the result hasn't been processed yet
        $cache = $this->_loadTransactionStatusFromCache($userName, $token);
        $orderIncrementId = $cache->getOrderIncrementId();
        if (empty($orderIncrementId)) {
            // 1. Sending PxFusion request to get the transaction result.
            $transactionResult = $this->_getTransactionStatus($token, 0);
            if (!$transactionResult) {

                $this->_notifierInterface->addMajor(
                    "Failed to process PxFusion response.",
                    "SessionId: " . $token . ". See Windcave extension log for more details."
                );
   
                $this->_logger->warning(__METHOD__ . " no response element. Json:" . $transactionResult);
                return;
            }

            $orderIncrementId = (string)$transactionResult['merchantReference'];
            $dpsTxnRef = (string)$transactionResult['dpsTxnRef'];

            /**
             * @var \Magento\Sales\Model\Order $order
             */
            $order = $this->_getOrderByIncrementId($orderIncrementId);
            // $order = $this->_orderRepository->get(intval($orderIncrementId, 10));
            if ($order == null) {

                $this->_notifierInterface->addMajor(
                    "Failed to load the order to process PxFusion result.",
                    "SessionId: " . $token . ", OrderId: " . $orderIncrementId . ", DpsTxnRef: " . $dpsTxnRef .
                    ". See Windcave extension log for more details."
                );

                $error = "Failed to load order: {$orderIncrementId}";
                $this->_logger->critical($error);
                $this->_redirectToCartPageWithError($error);
                return;
            }

            /**
             * @var \Magento\Quote\Model\Quote $quote
             */
            $quote = $this->_quoteFactory->create()->load($order->getQuoteId());

            // 2. Saving the result details into PaymentResult table
            $this->_savePaymentResult($userName, $token, $quote, $transactionResult);

            /**
             * @var \Magento\Sales\Model\Order\Payment $payment
             */
            $payment = $order->getPayment();

            $success = $transactionResult['status'] == CommonAction::APPROVED;

            if (!$success) {
                $this->_logger->info(__METHOD__ . " CheckoutSessionId: {$this->_checkoutSession->getSessionId()}");

                $resultToDisplay = $this->_findTransactionResultField($transactionResult, "CardHolderHelpText");
                if (!isset($resultToDisplay) || empty($resultToDisplay)) {
                    $resultToDisplay = $this->_findTransactionResultField($transactionResult, "CardHolderResponseText");
                }

                if (!isset($resultToDisplay) || empty($resultToDisplay)) {
                    $resultToDisplay = $transactionResult['responseText'];
                }

                $txnInfo = [];
                $txnInfo["DpsTransactionType"] = (string)$transactionResult['txnType'];
                $txnInfo["DpsResponseText"] = (string)$resultToDisplay;
                $txnInfo["ReCo"] = (string)$transactionResult['responseCode'];
                $txnInfo["DpsTransactionId"] = (string)$transactionResult['transactionId'];
                $txnInfo["DpsTxnRef"] = (string)$transactionResult['dpsTxnRef'];
                $txnInfo["CardName"] = (string)$transactionResult['cardName'];
                $txnInfo["CardholderName"] = (string)$transactionResult['cardHolderName'];
                
                // TODO: Save currency because I do not know how to get currency in payment::capture. Remove it when found a better way
                $txnInfo["Currency"] = (string)$transactionResult['currencyName'];
    
                $this->_logger->info(__METHOD__ . " Txn failed. TxnInfo: " . var_export($txnInfo, true));

                $txnType = (string)$transactionResult['txnType'];
                $txnTypeToDisplay = $txnType;
                if ($txnType == \Windcave\Payments\Model\Config\Source\PaymentOptions::AUTH) {
                    $txnTypeToDisplay = "Payment Authorization";
                }

                $order->addStatusHistoryComment("{$txnTypeToDisplay} failed. DpsTxnRef:{$txnInfo["DpsTxnRef"]} ReCo:{$txnInfo["ReCo"]} Response:{$txnInfo["DpsResponseText"]}");

                if (empty($this->_checkoutSession->getSessionId())) {
                    // FPRN notification won't have SessionID.
                    // In this case just cancelling order and that's it.

                    $this->_logger->info(__METHOD__ . " Processing is driven by FPRN.");
                    $this->_savePaymentInfoForFailedPayment($payment);

                    $txnType = (string)$transactionResult['txnType'];

                    $this->_logger->info(__METHOD__ . " FPRN. Adding txn.");

                    $this->_logger->info(__METHOD__ . " FPRN. Cancelling order.");
                    $order->setActionFlag(\Magento\Sales\Model\Order::ACTION_FLAG_CANCEL, true);
                    $order->cancel()->save();
                    $this->_logger->info(__METHOD__ . " FPRN. Done.");
                    return;
                }

                $this->_logger->info(__METHOD__ . " txn failed. Redirect. Attempting to restore card. lastSuccessQuoteId:" . $this->_checkoutSession->getLastSuccessQuoteId().
                    " lastQuoteId:" . $this->_checkoutSession->getLastQuoteId().
                    " lastOrderId:" . $this->_checkoutSession->getLastOrderId().
                    " lastRealOrderId:" . $this->_checkoutSession->getLastRealOrderId());

                // 3. Failed? Adding appropriate details into the order payment.
                $this->_savePaymentInfoForFailedPayment($payment);

                $this->_logger->info(__METHOD__ . "  Redirect. adding txn with details");
                $dpsTxnRef = (string)$transactionResult['dpsTxnRef'];

                $this->_logger->info(__METHOD__ . " Redirect. Saving order.");
                $order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT)
                    ->setStatus(CommonAction::STATUS_FAILED);
                $order->save();
                
                $error = "Payment failed. " . $resultToDisplay;
                $this->_logger->info($error);

                $this->_logger->info(__METHOD__ . " Sending payment failed email.");
                $this->_objectManager->get(\Magento\Checkout\Helper\Data::class)
                    ->sendPaymentFailedEmail($quote, $error);

                $this->_logger->info(__METHOD__ . " Redirect. Restoring cart.");
                $this->_restoreCart($order);

                $this->_logger->info(__METHOD__ . " Redirect. Redirecting to cart.");
                $this->_redirectToCartPageWithError($error);
                $this->_logger->info(__METHOD__ . " Redirect. Done.");
                return;
            }

            $this->_logger->info(__METHOD__ . " checking whether need to store bill card. " . var_export($payment->getAdditionalInformation(), true));
            $isRegisteredCustomer = !empty($quote->getCustomerId());
            if ($isRegisteredCustomer) {
                $paymentInfo = $payment->getAdditionalInformation();
                $enableAddBillCard = $this->_getBoolValue($paymentInfo, "EnableAddBillCard");
                if ($enableAddBillCard) {
                    $this->_saveRebillToken($order->getId(), $quote->getCustomerId(), $transactionResult);
                }
            }

            $this->_logger->info(__METHOD__ . " Marking order as paid.");
            // 4. Marking order as paid for the case when it's a Purchase. What to do in case of Auth?
            $success = $this->_markOrderAsPaid($order, $transactionResult);
            $this->_logger->info(__METHOD__ . " Marking order as paid. Done. Success:{$success}");

            if ($success) {
                // 4. Succeeded? Perfect. Adding details into the payment
                $this->_savePaymentInfoForSuccessfulPayment($payment, $transactionResult);

                $this->sendEmailForTheOrder($order);

                $this->_logger->info(__METHOD__ . " Redirecting to success page.");
                $this->_redirect("checkout/onepage/success", [
                    "_secure" => true
                ]);
            }
            return;
        }

        // The result seem to be processed by the other thread. Triggering WaitingQuote thing
        $this->_redirect(
            "pxpay2/payment/waitForResult",
            [
            "_secure" => true,
            "triedTimes" => 0,
            "reservedOrderId" => $orderIncrementId,
            "rm" => $this->_configuration->getRedirectOnErrorMode()
            ]
        );
    }

    /**
     *
     * @param \Magento\Sales\Model\Order $order
     * @param array $responseXmlElement
     * @return bool
     *  */
    private function _markOrderAsPaid(\Magento\Sales\Model\Order $order, $paymentResult)
    {
        $txnType = (string)$paymentResult['txnType'];
        $dpsTxnRef = (string)$paymentResult['dpsTxnRef'];
        $amount = floatval($paymentResult['amount']);

        $this->_logger->info(__METHOD__ . " orderId:{$order->getEntityId()} txnType:{$txnType} dpsTxnRef:{$dpsTxnRef} amount:{$amount}");

        $order->setCanSendNewEmailFlag(true);
        
        if ($txnType == \Windcave\Payments\Model\Config\Source\PaymentOptions::PURCHASE) {
            $this->_invoice($order, $txnType, $dpsTxnRef, $amount);
            return true;
        } elseif ($txnType == \Windcave\Payments\Model\Config\Source\PaymentOptions::AUTH) {
            $payment = $order->getPayment();
            $info = $payment->getAdditionalInformation();
            $txn = $this->_addTransaction($payment, $order, $txnType, $dpsTxnRef, false, $info);
            if ($txn) {
                $txn->save();
                $order->getPayment()->save();

                $order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT)
                  ->setStatus(CommonAction::STATUS_AUTHORIZED);
                
                $order->save();
            }
            return true;
        } else {
            $this->_logger->info(__("Unexpected txn type"));
            return false;
        }
    }

    /**
     * Gets the additional information for the order payment.
     *
     * @param string[] $info
     */
    private function _addTransaction(
        \Magento\Sales\Model\Order\Payment $payment,
        \Magento\Sales\Model\Order $order,
        $txnType,
        $dpsTxnRef,
        $isClosed,
        $info
    ) {
        $this->_transactionBuilder
          ->setPayment($payment)
          ->setOrder($order)
          ->setTransactionId($dpsTxnRef)
          ->setFailSafe(true);

        if (isset($info)) {
            $this->_transactionBuilder->setAdditionalInformation([\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS => (array)$info]);
        }

        $type = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH;
        if ($txnType == \Windcave\Payments\Model\Config\Source\PaymentOptions::PURCHASE) {
            $type = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_PAYMENT;
        }

        $txn = $this->_transactionBuilder->build($type);
        $txn->setIsClosed($isClosed);

        return $txn;
    }

    private function _invoice(\Magento\Sales\Model\Order $order, $txnType, $dpsTxnRef, $amount)
    {
        $invoice = $order->prepareInvoice();
        $invoice->getOrder()->setIsInProcess(true);

        $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
        $order->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);

        $invoice->setTransactionId($dpsTxnRef);
        $invoice->register()
                ->pay()
                ->save();

        $order->save();

        $message = __(
            'Invoiced amount of %1 Transaction ID: %2',
            $order->getBaseCurrency()->formatTxt($amount),
            $dpsTxnRef
        );
        $this->_addHistoryComment($order, $message);
    }

    /**
     * @desc Add a comment to order history
     *
     * @param \Magento\Sales\Mode\Order $order
     * @param string                    $message
     */
    private function _addHistoryComment($order, $message)
    {
        $history = $this->_orderHistoryFactory->create()
          ->setComment($message)
          ->setEntityName('order')
          ->setOrder($order);

        $history->save();
    }


    protected function _loadTransactionStatusFromCache($userName, $token)
    {
        $this->_logger->info(__METHOD__ . " userId:{$userName} token:{$token}");
        
        $paymentResultModel = $this->_objectManager->create("\Windcave\Payments\Model\PaymentResult");
        
        $paymentResultModelCollection = $paymentResultModel->getCollection()
            ->addFieldToFilter('token', $token)
            ->addFieldToFilter('user_name', $userName);
        
        $paymentResultModelCollection->getSelect();
        
        $dataBag = $this->_objectManager->create("\Magento\Framework\DataObject");

        $orderIncrementId = null;
        foreach ($paymentResultModelCollection as $item) {
            $orderIncrementId = $item->getReservedOrderId();
            $quoteId = $item->getQuoteId();
            $dataBag->setQuoteId($quoteId);

            $rawValue = $item->getRawXml();
            $paymentResult;
            try {
                $paymentResult = $this->_json->unserialize($rawValue);
            } catch (\Exception $e) {
                // TODO: deprecate unserialize completely
                $paymentResult = $this->_serialize->unserialize($rawValue);
            }
            if (!empty($paymentResult)) {
                $dataBag->setPaymentResult($paymentResult);
            }

            $this->_logger->info(__METHOD__ . " userId:{$userName} token:{$token} orderId:{$orderIncrementId} quoteId:{$quoteId}");
            break;
        }
        
        $dataBag->setOrderIncrementId($orderIncrementId);
        
        $this->_logger->info(__METHOD__ . " userId:{$userName} token:{$token} orderIncrementId:{$orderIncrementId}");
        return $dataBag;
    }

    private function _getBoolValue($array, $fieldName)
    {
        if (!isset($array)) {
            return false;
        }
        if (!isset($array[$fieldName])) {
            return false;
        }

        return filter_var($array[$fieldName], FILTER_VALIDATE_BOOLEAN);
    }

    private function _restoreCart(\Magento\Sales\Model\Order $order)
    {
        $this->_logger->info(__METHOD__);
        $orderId = $this->_checkoutSession->getLastRealOrderId();
        if ($order->getRealOrderId() == $orderId) {
            $this->_logger->info(__METHOD__ . " order id matches. LastReadOrderId:{$orderId} OrderToCancel:{$order->getRealOrderId()}");
            // restore the quote
            if ($this->_checkoutSession->restoreQuote()) {
                $this->_logger->info(__METHOD__ . " Quote has been restored.");
                $order->setActionFlag(\Magento\Sales\Model\Order::ACTION_FLAG_CANCEL, true);
                $order->cancel()->save();
            } else {
                $this->_logger->error(__METHOD__ . " Failed to restore the quote.");
            }
        } elseif ($order->getId()) {
            $this->_logger->warn(__METHOD__ . " attempting to cancel the order which is not the last one. " .
                "LastRealOrderId:{$this->_checkoutSession->getLastRealOrderId()} " .
                "OrderToCancel:{$order->getRealOrderId()}. Silently cancelling.");
            $order->setActionFlag(\Magento\Sales\Model\Order::ACTION_FLAG_CANCEL, true);
            $order->cancel()->save();
        }
    }

    /**
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param array $paymentResult
     */
    protected function _savePaymentInfoForSuccessfulPayment($payment, $paymentResult)
    {
        $this->_logger->info(__METHOD__);
        $info = $payment->getAdditionalInformation();
        
        $info = $this->_clearPaymentParameters($info);
        
        $info["DpsTransactionType"] = (string)$paymentResult['txnType'];
        $info["DpsResponseText"] = (string)$paymentResult['responseText'];
        $info["ReCo"] = (string)$paymentResult['responseCode'];
        $info["DpsTransactionId"] = (string)$paymentResult['transactionId'];
        $info["DpsTxnRef"] = (string)$paymentResult['dpsTxnRef'];
        $info["CardName"] = (string)$paymentResult['cardName'];
        $info["CardholderName"] = (string)$paymentResult['cardHolderName'];
        
        // TODO: Save currency because I do not know how to get currency in payment::capture. Remove it when found a better way
        $info["Currency"] = (string)$paymentResult['currencyName'];
        if ($this->_configuration->getAllowRebill()) {
            $info["DpsBillingId"] = (string)$paymentResult['dpsBillingId'];
        }
        
        $payment->unsAdditionalInformation(); // ensure DpsBillingId is not saved to database.
        $payment->setAdditionalInformation($info);
        
        $this->_logger->info(__METHOD__ . " info: ".var_export($info, true));
        $payment->save();
    }
    

    /**
     * @param \Magento\Sales\Model\Order\Payment $payment
     */
    private function _savePaymentInfoForFailedPayment($payment)
    {
        $this->_logger->info(__METHOD__);
        $info = $payment->getAdditionalInformation();
    
        $info = $this->_clearPaymentParameters($info);

        $payment->unsAdditionalInformation(); // ensure DpsBillingId is not saved to database.
        $payment->setAdditionalInformation($info);
        $payment->save();
    }
    
    private function _clearPaymentParameters($info)
    {
        $this->_logger->info(__METHOD__);
        
        unset($info["cartId"]);
        unset($info["guestEmail"]);
        unset($info["UseSavedCard"]);
        unset($info["DpsBillingId"]);
        unset($info["EnableAddBillCard"]);
        unset($info["method_title"]);
        unset($info["PxFusionSessionId"]);

        $this->_logger->info(__METHOD__ . " info: ".var_export($info, true));
        return $info;
    }

    private function _saveRebillToken($orderId, $customerId, $paymentResult)
    {
        $this->_logger->info(__METHOD__." orderId:{$orderId}, customerId:{$customerId}");
        $storeManager = $this->_objectManager->get("\Magento\Store\Model\StoreManagerInterface");
        $storeId = $storeManager->getStore()->getId();
        $billingModel = $this->_objectManager->create("\Windcave\Payments\Model\BillingToken");
        $billingModel->setData(
            [
                "customer_id" => $customerId,
                "order_id" => $orderId,
                "store_id" => $storeId,
                "masked_card_number" => (string)$paymentResult['cardNumber'],
                "cc_expiry_date" => (string)$paymentResult['dateExpiry'],
                "dps_billing_id" => (string)$paymentResult['dpsBillingId']
            ]
        );
        $billingModel->save();
    }

    protected function _savePaymentResult(
        $userName,
        $token,
        \Magento\Quote\Model\Quote $quote,
        $paymentResult
    ) {
        $this->_logger->info(__METHOD__ . " username:{$userName}, token:{$token}");
        $payment = $quote->getPayment();
        $method = $payment->getMethod();
        
        $paymentResultModel = $this->_objectManager->create("\Windcave\Payments\Model\PaymentResult");
        $paymentResultModel->setData(
            [
                "dps_transaction_type" => (string)$paymentResult['txnType'],
                "dps_txn_ref" => (string)$paymentResult['dpsTxnRef'],
                "method" => $method,
                "user_name" => $userName,
                "token" => $token,
                "quote_id" => $quote->getId(),
                "reserved_order_id" => (string)$paymentResult['merchantReference'],
                "updated_time" => new \DateTime(),
                "raw_xml" => (string)json_encode($paymentResult)
            ]
        );
        
        $paymentResultModel->save();
        
        $this->_logger->info(__METHOD__ . " done");
    }

    /**
     *
     * @return array
     */
    protected function _getTransactionStatus($transactionId, $triedCount)
    {
        $this->_logger->info(__METHOD__ . " transactionId:{$transactionId}, triedCount:{$triedCount}");
        
        $transactionResult = $this->_communication->getTransaction($transactionId);
        
        $status = $transactionResult["status"];
        if ($status == self::RESULT_UNKOWN && $triedCount < self::MAX_RETRY_COUNT) {
            return $this->_getTransactionStatus($transactionId, $triedCount + 1);
        }
        return $transactionResult;
    }
    
    private function _redirectToCartPageWithError($error)
    {
        $this->_logger->info(__METHOD__ . " error:{$error}");
        
        $this->_messageManager->addErrorMessage($error);

        $redirectDetails = $this->_configuration->getRedirectOnErrorDetails();
        $this->_redirect($redirectDetails['url'], $redirectDetails['params']);
    }

    /**
     * @param \Magento\Sales\Model\Order $order
     * @return void
     */
    public function sendEmailForTheOrder(\Magento\Sales\Model\Order $order)
    {
        $payment = $order->getPayment();
        $method = $payment->getMethod();

        $this->_logger->info(__METHOD__ . " orderId:" . $order->getId() . " paymentMethod:{$method}");
        
        if ($method != \Windcave\Payments\Model\Payment::PXPAY_CODE &&
            $method !=  \Windcave\Payments\Model\PxFusion\Payment::CODE) {
            return; // only send mail for payment methods in dps
        }
        
        if (!$order->getEmailSent()) {
            try {
                $this->_orderSender->send($order);
            } catch (\Exception $e) {
                $this->_logger->critical($e);
            }
        }
    }
}
