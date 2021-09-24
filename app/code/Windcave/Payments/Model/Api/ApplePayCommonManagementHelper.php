<?php
namespace Windcave\Payments\Model\Api;

class ApplePayCommonManagementHelper
{
    const STATUS_AUTHORIZED = 'windcave_authorized';
    const STATUS_FAILED = 'windcave_failed';
    /**
     *
     * @var \Magento\Framework\Message\ManagerInterface
     */
    private $_objectManager;
    /**
     *
     * @var \Windcave\Payments\Logger\DpsLogger
     */
    private $_logger;
    /**
     * @var \Magento\Checkout\Model\Session
     */
    private $_checkoutSession;
    /**
     *
     * @var \Magento\Framework\Message\ManagerInterface
     */
    private $_messageManager;
    /**
     *
     * @var \Windcave\Payments\Helper\ApplePay\Communication
     */
    private $_communication;
    /**
     * @var \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface
     */
    private $_transactionBuilder;
    /**
     *
     * @var \Magento\Sales\Model\Order\Status\HistoryFactory
     */
    private $_orderHistoryFactory;
    /**
     *
     * @var \Magento\Sales\Model\Order\Email\Sender\OrderSender
     */
    private $_orderSender;

    public function __construct(
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Framework\App\ResponseInterface $response,
        \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $txnBuilder,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Magento\Sales\Model\Order\Status\HistoryFactory $orderHistoryFactory,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender
    ) {
        $this->_objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_logger = $this->_objectManager->get("\Windcave\Payments\Logger\DpsLogger");
        $this->_communication = $this->_objectManager->get("\Windcave\Payments\Helper\ApplePay\Communication");

        $this->_orderSender = $orderSender;
        $this->_checkoutSession = $checkoutSession;
        $this->_messageManager = $messageManager;
        $this->_transactionBuilder = $txnBuilder;
        $this->_orderHistoryFactory = $orderHistoryFactory;

        $this->_logger->info(__METHOD__);
    }

    /**
     *
     * @param \Magento\Quote\Api\Data\PaymentInterface $method,
     * @param \Magento\Quote\Model\Quote $quote
     * @param bool $return
     */
    public function createTransaction($method, $quote)
    {
        $this->_logger->info(__METHOD__);
        $bRet = true;
        if (isset($method)) {
            $additionalData = $method->getAdditionalData();
            $this->_logger->info(__METHOD__ . "1.0");
            $transactionResult = $this->_communication->doCreatePaymentTransaction($additionalData, $quote);
            $this->_logger->info(__METHOD__ . "2.0");
            $order = $this->_checkoutSession->getLastRealOrder();
            $payment = $order->getPayment();
            $responseCode = $transactionResult["responseCode"];
            if ($responseCode == "00" && $transactionResult["httpCode"] == "200") { //APPROVED && OK
                $this->_logger->info(__METHOD__ . " Marking order as paid.");
                $success = $this->_markOrderAsPaid($order, $transactionResult);
                $this->_logger->info(__METHOD__ . " Marking order as paid. Done. Success:{$success}");
                if ($success) {
                    $this->_savePaymentInfoForSuccessfulPayment($payment, $transactionResult);
                    $this->_sendEmailForTheOrder($order);
                } else {
                    //Only happen if a centain payment type is NOT supported in our code.
                    $this->_redirectToCartPageWithError("Error: The payment type [" . $transactionResult['txnType'] . "] is invalid. Please contact the merchant.");
                }
            } else {
                $this->_logger->info(__METHOD__ . " CheckoutSessionId: {$this->_checkoutSession->getSessionId()}");
                $this->_savePaymentInfoForFailedPayment($payment);
                $order->addStatusHistoryComment("Payment failed. Session:".$this->_checkoutSession->getSessionId()." ReCo: " . $transactionResult["responseCode"] ." Response: ".$transactionResult["response"]);
                $this->_logger->info(__METHOD__ . " Set State Pending");
                $order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
                $this->_logger->info(__METHOD__ . " Set Status failed");
                $this->_logger->info(__METHOD__ . " >> ". ApplePayCommonManagementHelper::STATUS_FAILED);
                $order->setStatus(ApplePayCommonManagementHelper::STATUS_FAILED);
                $this->_logger->info(__METHOD__ . " Redirect. Saving order.");
                $order->save();

                $error = "Payment failed " . $responseCode;
                $this->_logger->info($error);
                $this->_logger->info(__METHOD__ . " Sending payment failed email.");
                $this->_objectManager->get(\Magento\Checkout\Helper\Data::class)->sendPaymentFailedEmail($quote, $error);
                $this->_logger->info(__METHOD__ . " Restoring the cart due to ".$transactionResult["response"].".");
                $this->_restoreCart($order);
                $this->_redirectToCartPageWithError($transactionResult["response"]);
                $bRet = false;
            }
            return $bRet;
        }
    }

    /**
     *
     * @param \Magento\Sales\Model\Order $order
     * @param array $responseXmlElement
     * @return bool
     *  */
    private function _markOrderAsPaid(\Magento\Sales\Model\Order $order, $paymentResult)
    {
        $status = true;
        $this->_logger->info(__METHOD__ . var_export($paymentResult, true));
        $txnType = $paymentResult['txnType'];
        $dpsTxnRef = $paymentResult['DpsTxnRef'];
        $amount = $paymentResult['amount'];

        $this->_logger->info(__METHOD__ . " orderId:{$order->getEntityId()} txnType:{$txnType} dpsTxnRef:{$dpsTxnRef} amount:{$amount}");

        $order->setCanSendNewEmailFlag(true);
        if ($txnType == \Windcave\Payments\Model\Config\Source\PaymentOptions::PURCHASE) {
            $this->_invoice($order, $txnType, $dpsTxnRef, $amount);
        } elseif ($txnType == \Windcave\Payments\Model\Config\Source\PaymentOptions::AUTH) {
            $payment = $order->getPayment();
            $txn = $this->_addTransaction($payment, $order, $txnType, $dpsTxnRef, false);
            if ($txn) {
                $txn->save();
                $order->getPayment()->save();
                $this->_logger->info(__METHOD__ . " Set state Pending");
                $order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
                $this->_logger->info(__METHOD__ . " Status Authorized");
                //Lets reuse the PxFusion status definition as we are also using that in Payment class
                $order->setStatus(\Windcave\Payments\Controller\PxFusion\CommonAction::STATUS_AUTHORIZED);
                $order->addStatusToHistory($order->getStatus(), ' Status as authorized.');
                $order->save();
            }
        } else {
            $this->_logger->info(__("Unexpected txn type"));
            $status = false;
        }
        return $status;
    }

    /**
     * @param \Magento\Sales\Model\Order $order
     * @return void
     */
    private function _sendEmailForTheOrder(\Magento\Sales\Model\Order $order)
    {
        $payment = $order->getPayment();
        $method = $payment->getMethod();

        $this->_logger->info(__METHOD__ . " orderId:" . $order->getId() . " paymentMethod:{$method}");
        
        if ($method !=  \Windcave\Payments\Model\ApplePay\Payment::APPLEPAY_CODE) {
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

    private function _invoice(\Magento\Sales\Model\Order $order, $txnType, $dpsTxnRef, $amount)
    {
        $this->_logger->info(__METHOD__ . " txnType:{$txnType} dpsTxnRef:{$dpsTxnRef} amount:{$amount}");
        $invoice = $order->prepareInvoice();
        $invoice->getOrder()->setIsInProcess(true);
        $this->_logger->info(__METHOD__ . " Set order status to Processing.");
        $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
        $order->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);
        $order->addStatusToHistory($order->getStatus(), ' Status is processing.');
        $invoice->setTransactionId($dpsTxnRef);
        $invoice->register()
                ->pay()
                ->save();
        $order->save();

        $message = __(
            'Invoiced amount of %1 Transaction ID: %2',
            $order->getBaseCurrency()->formatTxt(floatval($amount)),
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
    private function _addHistoryComment(\Magento\Sales\Model\Order $order, $message)
    {
        $this->_logger->info(__METHOD__ . " message:{$message}");
        $history = $this->_orderHistoryFactory->create()
          ->setComment($message)
          ->setEntityName('order')
          ->setOrder($order);
        $history->save();
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
        $isClosed
    ) {
        $this->_logger->info(__METHOD__ . " txnType:{$txnType} dpsTxnRef:{$dpsTxnRef} isClosed:{$isClosed}");
        $this->_transactionBuilder
          ->setPayment($payment)
          ->setOrder($order)
          ->setTransactionId($dpsTxnRef)
          ->setFailSafe(true);
        $info = $payment->getAdditionalInformation();
        if (isset($info)) {
            unset($info["paymentData"]);
            unset($info["transactionId"]);
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
    /**
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param array $paymentResult
     */
    private function _savePaymentInfoForSuccessfulPayment($payment, $paymentResult)
    {
        $this->_logger->info(__METHOD__);
        $info = $payment->getAdditionalInformation();
        
        $info = $this->_clearPaymentParameters($info);
        
        $info["DpsTransactionType"] = (string)$paymentResult['txnType'];
        $info["DpsResponseText"] = (string)$paymentResult['response'];
        $info["ReCo"] = (string)$paymentResult['responseCode'];
        $info["DpsTxnRef"] = (string)$paymentResult['DpsTxnRef'];
        $info["CardName"] = (string)$paymentResult['paymentType'];
        $info["CardholderName"] = (string)$paymentResult['cardholderName'];
        $info["Currency"] = (string)$paymentResult['currencyName'];
        $payment->unsAdditionalInformation();
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
    
        $this->_logger->info(__METHOD__ ." count of additional information: ". count($payment->getAdditionalInformation()));
        
        $info = $this->_clearPaymentParameters($info); //what do we need to clear?

        $payment->unsAdditionalInformation(); // ensure DpsBillingId is not saved to database.
        $payment->setAdditionalInformation($info);
        $payment->save();
    }
    private function _redirectToCartPageWithError($error)
    {
        $this->_logger->info(__METHOD__ . " error:{$error}");
        $this->_messageManager->addErrorMessage($error);
        $this->_response->setRedirect('checkout/cart', 301)->sendResponse();
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

    private function _clearPaymentParameters($info)
    {
        $this->_logger->info(__METHOD__);
        unset($info["paymentData"]);
        unset($info["transactionId"]);
        unset($info["cartId"]);
        unset($info["guestEmail"]);
        unset($info["paymentType"]);
        unset($info["method_title"]);
        return $info;
    }
}
