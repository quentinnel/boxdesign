<?php

namespace Windcave\Payments\Model\ApplePay;

use Magento\Payment\Model\Method\AbstractMethod;

//NOTE: AbstractMethod is already deprecated, next implementation should use MethodInterface
// https://magento.stackexchange.com/questions/199027/magento-2-reason-behind-deprecation-of-payment-method-class
class Payment extends AbstractMethod
{
    /**
     *
     * @var \Magento\Framework\App\ObjectManager
     */
    private $_objectManager;

    /**
     *
     * @var \Magento\Framework\Notification\NotifierInterface
     */
    private $_notifierInterface;

    private $_paymentHelper;

    const APPLEPAY_CODE = 'windcave_applepay';
    
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
     * @var string
     * @author Joseph McDermott <code@josephmcdermott.co.uk>
     */
    protected $_code = 'windcave_applepay';

      /**
       *
       * @var \Windcave\Payments\Helper\ApplePay\Configuration
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

    //If you change the construct then you have to compile the di again.
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
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
        $this->_quoteRepository = $quoteRepository;
        /** @var \Windcave\Payments\Helper\ApplePay\Configuration $configuration*/
        $this->_configuration = $this->_objectManager->get("\Windcave\Payments\Helper\ApplePay\Configuration");
        /** @var \Windcave\Payments\Helper\Communication $communication*/
        $this->_communication = $this->_objectManager->get("\Windcave\Payments\Helper\ApplePay\Communication");
        //We will reuse the PxFusion Communication for Complete, Void, Refund
        //$this->_communication = $this->_objectManager->get("\Windcave\Payments\Helper\PxFusion\Communication");
        $this->_paymentUtil = $this->_objectManager->get("\Windcave\Payments\Helper\PaymentUtil");

        $this->_notifierInterface = $notifierInterface;

        $this->_logger->info(__METHOD__);
    }
    /**
     * Check whether payment method can be used
     *
     * @param \Magento\Quote\Api\Data\CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        $this->_logger->info(__METHOD__);
        if ($quote != null) {
            $enabled = $this->_configuration->getEnabled($quote->getStoreId()) && $this->_configuration->isValidForApplePay($quote->getStoreId());
        } else {
            $enabled = $this->_configuration->getEnabled() && $this->_configuration->isValidForApplePay();
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

        $source = $data;
        if (isset($data['additional_data'])) {
            $source = $this->_objectManager->create("Magento\Framework\DataObject");
            $source->setData($data['additional_data']);
        }

        $info = [
            "paymentData" => $source->getData('paymentData'),
            "transactionId" => $source->getData('transactionId'),
            "cartId" => $source->getData('cartId'),
            "guestEmail" => $source->getData('guestEmail'),
            "paymentType" => "Purchase" //temporary hardcoded
        ];

        $infoInstance->setAdditionalInformation($info);
        $infoInstance->save();
        //$this->_logger->info(__METHOD__ . " saved additional information");
        //$this->_logger->info(__METHOD__ . " data:" . var_export($info, true));
        
        return $this;
    }
    // invoked by Magento\Sales\Model\Order\Payment::place
    public function getConfigPaymentAction()
    {
        $this->_logger->info(__METHOD__);
        $paymentType = $this->_configuration->getPaymentType($this->getStore());
        $paymentAction = "";
        
        if ($paymentType == \Windcave\Payments\Model\Config\Source\PaymentOptions::PURCHASE) {
            $paymentAction = \Magento\Payment\Model\Method\AbstractMethod::ACTION_AUTHORIZE_CAPTURE;
        }
        if ($paymentType == \Windcave\Payments\Model\Config\Source\PaymentOptions::AUTH) {
            $paymentAction = \Magento\Payment\Model\Method\AbstractMethod::ACTION_AUTHORIZE;
        }
        $this->_logger->info(__METHOD__ . " paymentType: {$paymentType} paymentAction: {$paymentAction}");
        return $paymentAction;
    }

    public function canCapture()
    {
        $this->_logger->info(__METHOD__);

        return $this->_canCapture;
    }

    // Invoked by Mage_Sales_Model_Order_Payment::capture
    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $this->_logger->info(__METHOD__ . " payment amount:" . $amount);
        $storeId = $this->getStore();
        $orderId = "unknown";
        $order = $payment->getOrder();
        if ($order) {
            $orderId = $order->getIncrementId();
        } else {
            $errorMessage = "Failed to find the order from payment to capture.";
            $this->_logger->critical(__METHOD__ . $errorMessage);
            throw new \Magento\Framework\Exception\PaymentException(__("{$errorMessage}. Please refer to Windcave module log for more details."));
        }
        if (!$payment->hasAdditionalInformation()) {
            $this->_logger->info(__METHOD__ . " orderId:{$orderId} additional_information is empty");
        }
        
        $info = $payment->getAdditionalInformation();

        $transactionId = $info["DpsTxnRef"]; // ensure it is unique
        $isPurchase = $info["DpsTransactionType"] == "Purchase";

        if (!$isPurchase) {
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
                $errorMessage = "Failed to capture order:{$orderId}, response from Windcave: {$responseXml}";
                $this->_logger->critical(__METHOD__ . $errorMessage);

                throw new \Magento\Framework\Exception\PaymentException(__("Failed to capture the order #{$orderId}. Please refer to Windcave module log for more details."));
            }
            $this->_paymentUtil->savePxPostResponse($payment, $responseXmlElement);
            if (!$responseXmlElement->Transaction || $responseXmlElement->Transaction["success"] != "1") {
                // $this->_paymentUtil->savePxPostResponse($payment, $responseXmlElement);
                $errorMessage = "Failed to capture order:{$orderId}, response from Windcave: {$responseXml}";
                $this->_logger->critical(__METHOD__ . $errorMessage);
                throw new \Magento\Framework\Exception\PaymentException(__("Failed to capture the order #{$orderId}. Please refer to Windcave module log for more details."));
            }
            $transactionId = (string)$responseXmlElement->DpsTxnRef; // use the DpsTxnRef of Complete
        }

        $payment->setTransactionId($transactionId);
        $payment->setIsTransactionClosed(1);
        $payment->setTransactionAdditionalInfo(\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS, $info);
        
        return $this;
    }

    public function canRefund()
    {
        $this->_logger->info(__METHOD__);

        return $this->_canRefund;
    }

    public function canRefundPartialPerInvoice()
    {
        $this->_logger->info(__METHOD__);

        return $this->_canRefundInvoicePartial;
    }

    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $this->_logger->info(__METHOD__);

        $storeId = $this->getStore();
        if (!$this->_configuration->isValidForPxPost($storeId)) {
            throw new \Magento\Framework\Exception\PaymentException(__("Windcave module is misconfigured. Please check the configuration before proceeding"));
        }

        $orderId = "unknown";
        $this->_logger->info(__METHOD__ . " start process.");
        $order = $payment->getOrder();
        if ($order) {
            $orderId = $order->getIncrementId();
            $this->_logger->info(__METHOD__ . " orderId:{$orderId}");
        } else {
            $errorMessage = "Failed to find the order from payment for refund.";
            $this->_logger->critical(__METHOD__ . $errorMessage);
            throw new \Magento\Framework\Exception\PaymentException(__("{$errorMessage}. Please refer to Windcave module log for more details."));
        }

        $info = $payment->getAdditionalInformation();
        $isAuth = $info["DpsTransactionType"] == "Auth";
        $this->_logger->info(__METHOD__ . " isAuth = {$isAuth}");
        if ($isAuth) {
            $dpsTxnRef = $payment->getParentTransactionId();
            $this->_logger->info(__METHOD__ . " dpsTxnRef = {$dpsTxnRef}");
        } else {
            $dpsTxnRef = $this->_paymentUtil->findDpsTxnRefForRefund($payment->getAdditionalInformation());
            $this->_logger->info(__METHOD__ . " 2.2 dpsTxnRef = {$dpsTxnRef}");
        }

        $currency = $order->getBaseCurrencyCode();
        if (!$dpsTxnRef) {
            $errorMessage = "Cannot issue a refund for the order #{$orderId}, as the payment has not been captured.";
            $this->_logger->critical(__METHOD__ . $errorMessage);
            throw new \Magento\Framework\Exception\PaymentException(__($errorMessage));
        }
        $this->_logger->info(__METHOD__ . " orderId:{$orderId} dpsTxnRef:{$dpsTxnRef} amount:{$amount} currency:{$currency}");
        
        $responseXml = $this->_communication->refund($amount, $currency, $dpsTxnRef, $storeId);
        $responseXmlElement = simplexml_load_string($responseXml);
        // TODO: refund occurs inside the DB transaction. So throwing the exception triggers transaction rollback. That means that _addTransaction doesn't work.
        if (!$responseXmlElement) {
            $errorMessage = " Failed to refund order:{$orderId}, Response from Windcave: {$responseXml}";
            $this->_logger->critical(__METHOD__ . $errorMessage);

            throw new \Magento\Framework\Exception\PaymentException(__("Failed to refund the order #{$orderId}. Please refer to Windcave module log for more details."));
        }
        $this->_logger->info(__METHOD__ . " 7.0");

        if (!$responseXmlElement->Transaction || $responseXmlElement->Transaction["success"] != "1") {
            $errorMessage = " Failed to refund order:{$orderId}. Response from Windcave: {$responseXml}";
            $this->_logger->critical(__METHOD__ . $errorMessage);
            
            $message = $this->getErrorMessage("Refund", $responseXmlElement, ". Please refer to Windcave module log for more details.");
            throw new \Magento\Framework\Exception\PaymentException(__($message));
        }
        $payment->setTransactionAdditionalInfo("DpsTxnRef", (string)$responseXmlElement->DpsTxnRef);
        $this->_paymentUtil->savePxPostResponse($payment, $responseXmlElement);
        $this->_logger->info(__METHOD__ . " Done");
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
        } else {
            $errorMessage = "Failed to find the order from payment.";
            $this->_logger->critical(__METHOD__ . $errorMessage);
            throw new \Magento\Framework\Exception\PaymentException(__("{$errorMessage}. Please refer to Windcave module log for more details."));
        }
        if (!$payment->hasAdditionalInformation()) {
            $this->_logger->info(__METHOD__ . " orderId:{$orderId} additional_information is empty");
        }
        
        $info = $payment->getAdditionalInformation();
        $isAuth = $info["DpsTransactionType"] == "Auth";
        $orderState = $order->getState();
        $orderStatus = $order->getStatus();
        // We will reuse the PxFusion Status
        if ($isAuth && $orderState == \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT && $orderStatus == \Windcave\Payments\Controller\PxFusion\CommonAction::STATUS_AUTHORIZED) {
            $dpsTxnRef = $info["DpsTxnRef"];
            $responseXml = $this->_communication->void($dpsTxnRef, $storeId);
            $responseXmlElement = simplexml_load_string($responseXml);
            $this->_logger->info(__METHOD__ . "  responseXml:" . $responseXml);
            if (!$responseXmlElement) {
                $this->_paymentUtil->saveInvalidResponse($payment, $responseXml);
                $errorMessage = "Failed to void the order:{$orderId}, response from Windcave: {$responseXml}";
                $this->_logger->critical(__METHOD__ . $errorMessage);
                throw new \Magento\Framework\Exception\PaymentException(__("Failed to void the order #{$orderId}. Please refer to Windcave module log for more details."));
            }
            $this->_paymentUtil->savePxPostResponse($payment, $responseXmlElement);
            if (!$responseXmlElement->Transaction || $responseXmlElement->Transaction["success"] != "1") {
                //$this->_paymentUtil->savePxPostResponse($payment, $responseXmlElement);
                $errorMessage = "Failed to void the order:{$orderId}. ReCo:" . $responseXmlElement->ReCo . "  ResponseText:" . $responseXmlElement->ResponseText . "  AcquirerReCo:" . $responseXmlElement->Transaction->AcquirerReCo . "  AcquirerResponseText:" . $responseXmlElement->Transaction->AcquirerResponseText;
                $this->_logger->critical(__METHOD__ . $errorMessage);
                throw new \Magento\Framework\Exception\PaymentException(__($errorMessage));
            }
        }
        return $this;
    }

    public function cancel(\Magento\Payment\Model\InfoInterface $payment)
    {
        $this->_logger->info(__METHOD__);
        $this->void($payment);
        return $this;
    }

    /**
     *
     * @return bool
     */
    public function isInitializeNeeded()
    {
        return parent::isInitializeNeeded();
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
        parent::initialize($paymentAction, $stateObject);
        $payment = $this->getInfoInstance();
        $order = $payment->getOrder();
        $stateObject->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus($this->getConfigData('order_status'));
        $stateObject->setIsNotified(false);
        $this->_logger->info(__METHOD__ . " Order status changed to pending");
        $order->setCanSendNewEmailFlag(false);
        $order->save();
        return $this;
    }

    private function _buildReturnUrl()
    {
        $this->_logger->info(__METHOD__);
        $url = $this->_url->getUrl('pxpay2/applepay/result', ['_secure' => true]);
        $this->_logger->info(__METHOD__ . " url: {$url} ");
        return $url;
    }
}
