<?php

// Magento\Framework\DataObject implements the magic call function
namespace Windcave\Payments\Model\PxFusion;

class Payment extends \Magento\Payment\Model\Method\AbstractMethod
{

    const CODE = "windcave_pxfusion";

    /**
     *
     * @var \Magento\Framework\App\ObjectManager
     */
    private $_objectManager;

    protected $_code = "windcave_pxfusion";

    protected $_infoBlockType = 'Windcave\Payments\Block\Info';

    protected $_isGateway = true;

    protected $_canAuthorize = true;

    protected $_canCapture = true;

    protected $_canCapturePartial = true;

    protected $_canUseInternal = false;

    protected $_canUseCheckout = true;

    protected $_canUseForMultishipping = false;

    protected $_canRefund = true;

    protected $_canRefundInvoicePartial = true;

    protected $_isInitializeNeeded = true;

    /**
     *
     * @var \Windcave\Payments\Helper\PaymentUtil
     */
    protected $_paymentUtil;

    /**
     *
     * @var \Windcave\Payments\Helper\PxFusion\Configuration
     */
    protected $_configuration;

    /**
     *
     * @var \Windcave\Payments\Helper\PxFusion\Communication
     */
    protected $_communication;

    /**
     *
     * @var \Magento\Quote\Model\QuoteRepository
     */
    protected $_quoteRepository;

    /**
     *
     * @var \Magento\Framework\Url
     */
    private $_url;

    /**
     *
     * @var \Magento\Checkout\Model\Session
     */
    private $_checkoutSession;

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
     * @var \Magento\Framework\Notification\NotifierInterface
     */
    private $_notifierInterface;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Checkout\Model\Session $session,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Quote\Model\QuoteRepository $quoteRepository,
        \Magento\Framework\Url $url,
        \Magento\Sales\Model\Order\Status\HistoryFactory $orderHistoryFactory,
        \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $txnBuilder,
        \Magento\Framework\Notification\NotifierInterface $notifierInterface,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->_objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_logger = $this->_objectManager->get("\Windcave\Payments\Logger\DpsLogger");
        $this->_paymentUtil = $this->_objectManager->get("\Windcave\Payments\Helper\PaymentUtil");
        $this->_configuration = $this->_objectManager->get(\Windcave\Payments\Helper\PxFusion\Configuration::class);
        $this->_communication = $this->_objectManager->get("\Windcave\Payments\Helper\PxFusion\Communication");
        $this->_quoteRepository = $quoteRepository;
        $this->_url = $url;
        $this->_checkoutSession = $session;
        $this->_transactionBuilder = $txnBuilder;
        $this->_orderHistoryFactory = $orderHistoryFactory;
        $this->_notifierInterface = $notifierInterface;
        $this->_logger->info(__METHOD__);
    }

    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        $this->_logger->info(__METHOD__);
        if ($quote != null) {
            $enabled = $this->_configuration->getEnabled($quote->getStoreId()) && $this->_configuration->isValidForPxFusion($quote->getStoreId());
        } else {
            $enabled = $this->_configuration->getEnabled() && $this->_configuration->isValidForPxFusion();
        }
        $this->_logger->info(__METHOD__ . " enabled:" . $enabled);
        return $enabled;
    }

    // invoked by Magento\Quote\Model\PaymentMethodManagement::set
    public function assignData(\Magento\Framework\DataObject $data)
    {
        $this->_logger->info(__METHOD__ . " data:" . var_export($data, true));
        
        $infoInstance = $this->getInfoInstance();
        
        $info = $infoInstance->getAdditionalInformation();
        if (isset($info) && !empty($info['DpsTxnRef'])) {
            $this->_logger->info(__METHOD__ . " payment finished. DpsTxnRef:" . $info['DpsTxnRef']);
            return $this; //The transaction is processed.
        }
        
        // sessionId and transactionId is always sent by JS (dps-pxfusion.js/getPaymentData)
        $source = $data;
        if (isset($data['additional_data'])) {
            $source = $this->_objectManager->create("Magento\Framework\DataObject");
            $source->setData($data['additional_data']);
        }
        
        $info = [
            "sessionId" => $source->getData('sessionId'),
            "transactionId" => $source->getData('transactionId'),
            "cartId" => $source->getData('cartId'),
            "guestEmail" => $source->getData('guestEmail')
        ];

        $dpsBillingId = $source->getData("windcave_billingId");
        $info["UseSavedCard"] = filter_var($source->getData("windcave_useSavedCard"), FILTER_VALIDATE_BOOLEAN);
        $info["EnableAddBillCard"] = filter_var($source->getData("windcave_enableAddBillCard"), FILTER_VALIDATE_BOOLEAN);
        if (isset($dpsBillingId) && !empty($dpsBillingId)) {
            $info["DpsBillingId"] = $dpsBillingId;
            $info["UseSavedCard"] = true;
            
            $info["EnableAddBillCard"] = false; // Do not add billing token when rebill.
        } else {
            $info["UseSavedCard"] = false;
        }

        $infoInstance->setAdditionalInformation($info);
        $infoInstance->save();
        
        return $this;
    }

    public function getConfigPaymentAction()
    {
        // invoked by Magento\Sales\Model\Order\Payment::place
        $this->_logger->info(__METHOD__);
        $paymentType = $this->_configuration->getPaymentType($this->getStore());
        $paymentAction = "";
        
        if ($paymentType == \Windcave\Payments\Model\Config\Source\PaymentOptions::PURCHASE) {
            $paymentAction = \Magento\Payment\Model\Method\AbstractMethod::ACTION_AUTHORIZE_CAPTURE;
        }
        if ($paymentType == \Windcave\Payments\Model\Config\Source\PaymentOptions::AUTH) {
            $paymentAction = \Magento\Payment\Model\Method\AbstractMethod::ACTION_AUTHORIZE;
        }
        $this->_logger->info(__METHOD__ . " paymentAction: {$paymentAction}");
        return $paymentAction;
    }

    public function canCapture()
    {
        return $this->_canCapture;
    }

    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        // refer to Magento\Sales\Model\Order\Payment\Transaction\Builder::build for which fields should be set.
        $this->_logger->info(__METHOD__);

        $orderId = "unknown";
        $order = $payment->getOrder();
        if ($order) {
            $orderId = $order->getIncrementId();
        }

        if (!$payment->hasAdditionalInformation()) {
            $this->_logger->info(__METHOD__ . " orderId:{$orderId} additional_information is empty");
        }

        
        $isPurchase = ($payment->getAdditionalInformation("DpsTransactionType") == "Purchase");
        $info = $payment->getAdditionalInformation();
        
        $transactionId = $info["DpsTxnRef"]; // ensure it is unique
        
        if (!$isPurchase) {
            $storeId = $this->getStore();
            if (!$this->_configuration->isValidForPxPost($storeId)) {
                throw new \Magento\Framework\Exception\PaymentException(__("Windcave module is misconfigured. Please check the configuration before proceeding"));
            }

            $currency = $info["Currency"];
            $dpsTxnRef = $info["DpsTxnRef"];
            $responseXml = $this->_communication->complete($amount, $currency, $dpsTxnRef, $storeId);
            $responseXmlElement = simplexml_load_string($responseXml);
            $this->_logger->info(__METHOD__ . "  responseXml:" . $responseXml);
            if (!$responseXmlElement) {
                $this->_paymentUtil->saveInvalidResponse($payment, $responseXml);
                $errorMessage = "Failed to capture order:{$orderId}. Response from Windcave:" . $responseXml;
                $this->_logger->critical(__METHOD__ . $errorMessage);
                throw new \Magento\Framework\Exception\PaymentException(__($errorMessage));
            }
            if (!$responseXmlElement->Transaction || $responseXmlElement->Transaction["success"] != "1") {
                $this->_paymentUtil->savePxPostResponse($payment, $responseXmlElement);
                $errorMessage = "Failed to capture order:{$orderId}. ReCo:" . $responseXmlElement->ReCo . "  ResponseText:" . $responseXmlElement->ResponseText . "  AcquirerReCo:" . $responseXmlElement->Transaction->AcquirerReCo . "  AcquirerResponseText:" . $responseXmlElement->Transaction->AcquirerResponseText;
                $this->_logger->critical(__METHOD__ . $errorMessage);
                throw new \Magento\Framework\Exception\PaymentException(__($errorMessage));
            }
            
            $this->_paymentUtil->savePxPostResponse($payment, $responseXmlElement);
            
            $transactionId = (string)$responseXmlElement->DpsTxnRef; // use the DpsTxnRef of Complete
        }

        $payment->setTransactionId($transactionId);
        $payment->setIsTransactionClosed(1);
        $payment->setTransactionAdditionalInfo(\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS, $info);
        
        return $this;
    }

    public function canRefund()
    {
        return $this->_canRefund;
    }

    public function canRefundPartialPerInvoice()
    {
        return $this->canRefund();
    }

    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        //TODO: same code with PxPay2. move the common code to separate class to reuse code.
        $this->_logger->info(__METHOD__);

        $storeId = $this->getStore();
        if (!$this->_configuration->isValidForPxPost($storeId)) {
            throw new \Magento\Framework\Exception\PaymentException(__("Windcave module is misconfigured. Please check the configuration before proceeding"));
        }

        $orderId = "unknown";
        $order = $payment->getOrder();
        if ($order) {
            $orderId = $order->getIncrementId();
        }

        $isAuth = ($payment->getAdditionalInformation("DpsTransactionType") == "Auth");
        if ($isAuth) {
            $dpsTxnRef = $payment->getParentTransactionId();
        } else {
            $dpsTxnRef = $this->_paymentUtil->findDpsTxnRefForRefund($payment->getAdditionalInformation());
        }

        $currency = $order->getBaseCurrencyCode();
        
        if (!$dpsTxnRef) {
            $errorMessage = "Cannot issue a refund for the order #{$orderId}, as the payment has not been captured.";
            $this->_logger->critical(__METHOD__ . $errorMessage);
            throw new \Magento\Framework\Exception\PaymentException(__($errorMessage));
        }

        $this->_logger->info(__METHOD__ . " orderId:{$orderId} dpsTxnRef:{$dpsTxnRef} amount:{$amount} currency:{$currency}");

        $responseXml = $this->_communication->refund($amount, $currency, $dpsTxnRef, $this->getStore());
        $responseXmlElement = simplexml_load_string($responseXml);
        
        $this->_logger->info(__METHOD__ . " orderId:{$orderId}");
        
        if (!$responseXmlElement) {
            $errorMessage = " Failed to refund order:{$orderId}, response from Windcave: {$responseXml}";
            $this->_logger->critical(__METHOD__ . $errorMessage);
            
            throw new \Magento\Framework\Exception\PaymentException(__("Failed to refund the order #{$orderId}. Please refer to Windcave module log for more details."));
        }
        
        if (!$responseXmlElement->Transaction || $responseXmlElement->Transaction["success"] != "1") {
            $errorMessage = " Failed to refund order:{$orderId}. response from Windcave: {$responseXml}";
            $this->_logger->critical(__METHOD__ . $errorMessage);

            $message = $this->getErrorMessage("Refund", $responseXmlElement, ". Please refer to Windcave module log for more details.");
            throw new \Magento\Framework\Exception\PaymentException(__($message));
        }

        $payment->setTransactionAdditionalInfo("DpsTxnRef", (string)$responseXmlElement->DpsTxnRef);
        $this->_paymentUtil->savePxPostResponse($payment, $responseXmlElement);

        return $this;
    }

    public function canVoid()
    {
        $this->_logger->info(__METHOD__);

        $payment = $this->getInfoInstance();
        $order = $payment->getOrder();

        $isAuth = ($payment->getAdditionalInformation("DpsTransactionType") == "Auth");
        
        $orderState = $order->getState();
        $orderStatus = $order->getStatus();
        if ($isAuth && $orderState == \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT && $orderStatus == \Windcave\Payments\Controller\PxFusion\CommonAction::STATUS_AUTHORIZED) {
            $this->_canVoid = true;
        } else {
            $this->_canVoid = false;
        }

        return $this->_canVoid;
    }


    public function void(\Magento\Payment\Model\InfoInterface $payment)
    {
        $this->_logger->info(__METHOD__);

        parent::void($payment);

        $storeId = $this->getStore();
        if (!$this->_configuration->isValidForPxPost($storeId)) {
            throw new \Magento\Framework\Exception\PaymentException(__("Windcave module is misconfigured. Please check the configuration before proceeding"));
        }

        $orderId = "unknown";
        $order = $payment->getOrder();
        if ($order) {
            $orderId = $order->getIncrementId();
        }
        
        if (!$payment->hasAdditionalInformation()) {
            $this->_logger->info(__METHOD__ . " orderId:{$orderId} additional_information is empty");
        }

        $info = $payment->getAdditionalInformation();
        $isAuth = $payment->getAdditionalInformation("DpsTransactionType")  == "Auth";
        
        $orderState = $order->getState();
        $orderStatus = $order->getStatus();
        if ($isAuth && $orderState == \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT && $orderStatus == \Windcave\Payments\Controller\PxFusion\CommonAction::STATUS_AUTHORIZED) {
            $dpsTxnRef = $info["DpsTxnRef"];
            $responseXml = $this->_communication->void($dpsTxnRef, $storeId);
            $responseXmlElement = simplexml_load_string($responseXml);
            if (!$responseXmlElement) {
                $this->_paymentUtil->saveInvalidResponse($payment, $responseXml);
                $errorMessage = "Failed to void the order:{$orderId}, response from Windcave: {$responseXml}";
                $this->_logger->critical(__METHOD__ . $errorMessage);
                throw new \Magento\Framework\Exception\PaymentException(__("Failed to void the order #{$orderId}. Please refer to Windcave module log for more details."));
            }
            if (!$responseXmlElement->Transaction || $responseXmlElement->Transaction["success"] != "1") {
                $this->_paymentUtil->savePxPostResponse($payment, $responseXmlElement);
                $errorMessage = "Failed to void the order:{$orderId}. ReCo:" . $responseXmlElement->ReCo . "  ResponseText:" . $responseXmlElement->ResponseText . "  AcquirerReCo:" . $responseXmlElement->Transaction->AcquirerReCo . "  AcquirerResponseText:" . $responseXmlElement->Transaction->AcquirerResponseText;
                $this->_logger->critical(__METHOD__ . $errorMessage);
                throw new \Magento\Framework\Exception\PaymentException(__($errorMessage));
            }
            
            $this->_paymentUtil->savePxPostResponse($payment, $responseXmlElement);
        }
        return $this;
    }

    public function cancel(\Magento\Payment\Model\InfoInterface $payment)
    {
        $this->_logger->info(__METHOD__);
        $this->void($payment);
        return $this;
    }


    public function generateComment($txnType, $dpsTxnRef, $reCo, $responseText, $addText = null)
    {
        $this->_logger->info(__METHOD__);
        $comment = "${txnType} has failed.";
        if (isset($dpsTxnRef)) {
            $comment .= " DpsTxnRef:{$dpsTxnRef}";
        }

        if (isset($reCo)) {
            $comment .= " ReCo:{$reCo}";
        }

        if (isset($responseText)) {
            $comment .= " Response: {$responseText}";
        }

        if (isset($addText)) {
            $comment .= " " . $addText;
        }

        return $comment;
    }

    private function getErrorMessage($txnType, $responseXmlElement, $addText = null)
    {
        $reCo = "";
        $transactionXmlElement = $responseXmlElement->Transaction;
        if ($transactionXmlElement) {
            $reCo = (string)$transactionXmlElement->ReCo;
        } else {
            $reCo = (string)$responseXmlElement->ReCo;
        }
        $dpsTxnRef = (string)$responseXmlElement->DpsTxnRef;
        $responseText = (string)$responseXmlElement->ResponseText;

        $message = $this->generateComment($txnType, $dpsTxnRef, $reCo, $responseText, $addText);

        return $message;
    }

    private function _buildReturnUrl()
    {
        $this->_logger->info(__METHOD__);
        $url = $this->_url->getUrl('pxpay2/pxfusion/result', ['_secure' => true]);
        $this->_logger->info(__METHOD__ . " url: {$url} ");
        return $url;
    }

    private function _restoreCart(\Magento\Sales\Model\Order $order)
    {
        $this->_logger->info(__METHOD__);
        $this->_logger->info(__METHOD__ . " LastRealOrderId:" . $this->_checkoutSession->getLastRealOrderId() . "  RealOrderId:" . $order->getRealOrderId());

        // restore the quote
        if ($this->_checkoutSession->restoreQuote()) {
            $this->_logger->info(__METHOD__ . " Quote has been restored.");
            $order->setActionFlag(\Magento\Sales\Model\Order::ACTION_FLAG_CANCEL, true);
            $order->cancel();
        } else {
            $this->_logger->error(__METHOD__ . " Failed to restore the quote.");
        }
    }

    /**
     * Method that will be executed instead of authorize or capture
     * if flag isInitializeNeeded set to true
     *
     * @param string $paymentAction
     * @param object $stateObject
     *
     * @return $this
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function initialize($paymentAction, $stateObject)
    {
        $this->_logger->info(__METHOD__);

        parent::initialize($paymentAction, $stateObject);

        $payment = $this->getInfoInstance();
        $order = $payment->getOrder();

        $additionalInfo = $payment->getAdditionalInformation();
        if (isset($additionalInfo["UseSavedCard"]) && $additionalInfo["UseSavedCard"] == true && isset($additionalInfo["DpsBillingId"])) {
            if ($this->_configuration->getRequireCvcForRebilling($order->getStoreId())) {
                $this->initializeForRebillingWithCvc($stateObject, $payment, $order, $additionalInfo);
            } else {
                $this->initializeForRebilling($stateObject, $payment, $order, $additionalInfo);
            }
        } else {
            $this->initializeForOneOffCharge($stateObject, $payment, $order, $additionalInfo);
        }
        $order->save();

        return $this;
    }


    private function initializeForRebilling($stateObject, $payment, $order, $additionalInfo)
    {
        $this->_logger->info(__METHOD__);
        $result = false;
        $quoteId = $order->getQuoteId();
        try {
            $quote = $this->_quoteRepository->get($quoteId);

            $order->save();

            $result = $this->_communication->rebill($quote, $order, $additionalInfo["DpsBillingId"], null);

            $response = $result['response'];
            $authorized = $result['outcomeReceived'] == true && $response->Transaction->Authorized == 1;

            $payment->setAdditionalInformation("PxFusionData", [
                'type' => "Rebilling",
                'authorized' => $authorized,
            ]);

            if (!$authorized) {
                $dpsTxnRef = (string)$response->Transaction->DpsTxnRef;
                $reCo = (string)$response->Transaction->ReCo;
                $responseText = (string)$response->ResponseText;
                $acquirerReCo = (string)$response->Transaction->AcquirerReCo;
                $acquirerResponseText = (string)$response->Transaction->AcquirerResponseText;
       
                $order->addStatusHistoryComment("Rebilling has failed. DpsTxnRef: {$dpsTxnRef}  ReCo: {$reCo}  Response: {$responseText}  AcquirerReCo: {$acquirerReCo}  AcquirerResponse: {$acquirerResponseText}");

                $this->_restoreCart($order);

                $stateObject->setState(\Magento\Sales\Model\Order::STATE_CANCELED);
                $stateObject->setStatus(\Windcave\Payments\Controller\PxFusion\CommonAction::STATUS_FAILED);
                $stateObject->setIsNotified(false);

                $payment->setAdditionalInformation("PxFusionData", [
                    'message' => "Failed to charge the card. Please use another card or contact the support."
                ]);

                $this->_logger->info(__METHOD__ . " Order status changed to failed");
            } else {
                $this->_markOrderAsPaid($order, $response);

                $stateObject->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT)
                    ->setStatus(\Windcave\Payments\Controller\PxFusion\CommonAction::STATUS_AUTHORIZED);
                $stateObject->setIsNotified(false);
                $this->_logger->info(__METHOD__ . " Order status changed to authorized");
            }
        } catch (\Magento\Framework\Exception\State\InvalidTransitionException $exception) {
            // TODO: need to do something here
            $this->_notifierInterface->addMajor(
                "Failed to charge the saved card.",
                "OrderId: " . $order->getRealOrderId() . ", QuoteId: " . $quoteId .
                ". See Windcave extension log for more details."
            );

            throw new \Magento\Framework\Exception\LocalizedException(
                __("Internal error while processing quote #{$quoteId}. Please contact support.")
            );
        }
    }

    private function initializeForRebillingWithCvc($stateObject, $payment, $order, $additionalInfo)
    {
        //TODO: here we need to check whether it's a rebilling or not. If rebilling, to PxPost rebill and save the result
        $this->_logger->info(__METHOD__ . " Attempting to generate PxFusion session");
        $quoteId = $order->getQuoteId();
        $sessionId = "";
        try {
            $quote = $this->_quoteRepository->get($quoteId);
            $sessionId = $this->_communication->createTransaction($quote, $this->_buildReturnUrl(), false, $additionalInfo["DpsBillingId"]);
            if (empty($sessionId)) {
                throw new \Magento\Framework\Exception\State\InvalidTransitionException(__("Failed to create PxFusion session"));
            }
            $payment->setAdditionalInformation("PxFusionData", [
                'type' => "RebillingWithCvc",
                'sessionId' => $sessionId
            ]);
        } catch (\Magento\Framework\Exception\State\InvalidTransitionException $exception) {
            // TODO: need to do something here
            $this->_notifierInterface->addMajor(
                "Failed to generate PxFusion session",
                "OrderId: " . $order->getRealOrderId() . ", QuoteId: " . $quoteId .
                ". See Windcave extension log for more details."
            );

            throw new \Magento\Framework\Exception\LocalizedException(
                __("Internal error while processing quote #{$quoteId}. Please contact support.")
            );
        }

        $stateObject->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus($this->getConfigData('order_status'));
        $stateObject->setIsNotified(false);
        $this->_logger->info(__METHOD__ . " Order status changed to pending payment");

        $order->setCanSendNewEmailFlag(false);

        $this->_logger->info(__METHOD__ . "  realOrderId:" . $order->getRealOrderId() . " Id:" . $order->getId() . " SessionId:" . $sessionId);
    }

    private function initializeForOneOffCharge($stateObject, $payment, $order, $additionalInfo)
    {
        //TODO: here we need to check whether it's a rebilling or not. If rebilling, to PxPost rebill and save the result
        $this->_logger->info(__METHOD__ . " Attempting to generate PxFusion session");
        $quoteId = $order->getQuoteId();
        $addBillCard = isset($additionalInfo["EnableAddBillCard"]) && $additionalInfo["EnableAddBillCard"] == true;
        $sessionId = "";
        try {
            $quote = $this->_quoteRepository->get($quoteId);
            $sessionId = $this->_communication->createTransaction($quote, $this->_buildReturnUrl(), $addBillCard);
            if (empty($sessionId)) {
                throw new \Magento\Framework\Exception\State\InvalidTransitionException(__("Failed to create PxFusion session"));
            }
            $payment->setAdditionalInformation("PxFusionData", [
                'type' => "Payment",
                'sessionId' => $sessionId
            ]);
        } catch (\Magento\Framework\Exception\State\InvalidTransitionException $exception) {
            // TODO: need to do something here
            $this->_notifierInterface->addMajor(
                "Failed to generate PxFusion session",
                "OrderId: " . $order->getRealOrderId() . ", QuoteId: " . $quoteId .
                ". See Windcave extension log for more details."
            );

            throw new \Magento\Framework\Exception\LocalizedException(
                __("Internal error while processing quote #{$quoteId}. Please contact support.")
            );
        }

        $stateObject->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus($this->getConfigData('order_status'));
        $stateObject->setIsNotified(false);
        $this->_logger->info(__METHOD__ . " Order status changed to pending payment");

        $order->setCanSendNewEmailFlag(false);

        $this->_logger->info(__METHOD__ . "  realOrderId:" . $order->getRealOrderId() . " Id:" . $order->getId() . " SessionId:" . $sessionId);
    }


    /**
     *
     * @param \Magento\Sales\Model\Order $order
     * @param $responseXmlElement
     * @return bool
     *  */
    private function _markOrderAsPaid(\Magento\Sales\Model\Order $order, $paymentResult)
    {
        $txnType = (string)$paymentResult->Transaction->TxnType;
        $dpsTxnRef = (string)$paymentResult->Transaction->DpsTxnRef;
        $amount = floatval($paymentResult->Transaction->Amount);

        $this->_logger->info(__METHOD__ . " orderId:{$order->getEntityId()} txnType:{$txnType} dpsTxnRef:{$dpsTxnRef} amount:{$amount}");

        $order->setCanSendNewEmailFlag(true);
        
        if ($txnType == \Windcave\Payments\Model\Config\Source\PaymentOptions::PURCHASE) {
            $this->_invoice($order, $txnType, $dpsTxnRef, $amount);
            return true;
        } elseif ($txnType == \Windcave\Payments\Model\Config\Source\PaymentOptions::AUTH) {
            $payment = $order->getPayment();
            $info = $payment->getAdditionalInformation();

            //we need this entry for canVoid and void func.
            $info["DpsTransactionType"] = $txnType;
            $info["DpsTxnRef"] = $dpsTxnRef;
            $info["ReCo"] = (string)$paymentResult->Transaction->ReCo;
            $info["DpsResponseText"] = (string)$paymentResult->ResponseText;
            $info["CardName"] = (string)$paymentResult->Transaction->CardName;

            $payment->unsAdditionalInformation();
            $payment->setAdditionalInformation($info);
            $txn = $this->_addTransaction($payment, $order, $txnType, $dpsTxnRef, false, $info);
            if ($txn) {
                $txn->save();
                $order->getPayment()->save();

                $order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT)
                  ->setStatus(\Windcave\Payments\Controller\PxFusion\CommonAction::STATUS_AUTHORIZED);
                
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
}
