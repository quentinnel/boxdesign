<?php

// Magento\Framework\DataObject implements the magic call function

// Create payment module http://www.josephmcdermott.co.uk/basics-creating-magento2-payment-method
// https://github.com/magento/magento2-samples/tree/master/sample-module-payment-provider
namespace Windcave\Payments\Model;

class PaymentHelper
{
    /**
     *
     * @var \Windcave\Payments\Helper\PaymentUtil
     */
    protected $_paymentUtil;
    
    /**
     *
     * @var \Windcave\Payments\Helper\Configuration
     */
    protected $_configuration;
    
    /**
     *
     * @var \Windcave\Payments\Helper\Communication
     */
    protected $_communication;
    
    public function __construct()
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_logger = $objectManager->get("\Windcave\Payments\Logger\DpsLogger");
        $this->_paymentUtil = $objectManager->get("\Windcave\Payments\Helper\PaymentUtil");
        $this->_communication = $objectManager->get("\Windcave\Payments\Helper\Communication");
        $this->_logger->info(__METHOD__);
    }
    

    public function init($configuration, $communication)
    {
        $this->_logger->info(__METHOD__);
        
        $this->_configuration = $configuration;
        $this->_communication = $communication;
    }
    
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        $this->_logger->info(__METHOD__);
        if ($quote != null) {
            $enabled = $this->_configuration->getEnabled($quote->getStoreId()) && $this->_configuration->isValidForPxPay($quote->getStoreId());
        } else {
            $enabled = $this->_configuration->getEnabled() && $this->_configuration->isValidForPxPay();
        }
        $this->_logger->info(__METHOD__ . " enabled:" . $enabled);
        return $enabled;
    }
    
    public function getConfigPaymentAction($storeId)
    {
        // invoked by Magento\Sales\Model\Order\Payment::place
        $this->_logger->info(__METHOD__);
        $paymentType = $this->_configuration->getPaymentType($storeId);
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
    
    public function canCapture($storeId, $info)
    {
        $this->_logger->info(__METHOD__);

        $paymentType = $this->_configuration->getPaymentType($storeId);
        $canCapture = true;
        if ($paymentType == \Windcave\Payments\Model\Config\Source\PaymentOptions::PURCHASE) {
            $canCapture = true;
        } else {
            $canCapture = !($this->canRefund($info)); // Complete transaction is processed.
        }
        $canCapture = $canCapture && !$this->_paymentUtil->wasVoided($info);
        $this->_logger->info(__METHOD__ . " canCapture:{$canCapture}");
        return $canCapture;
    }
    
    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount, $storeId)
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
            if (!$responseXmlElement->Transaction || $responseXmlElement->Transaction["success"] != "1") {
                $this->_paymentUtil->savePxPostResponse($payment, $responseXmlElement);
                $errorMessage = "Failed to capture order:{$orderId}, response from Windcave: {$responseXml}";
                $this->_logger->critical(__METHOD__ . $errorMessage);
                throw new \Magento\Framework\Exception\PaymentException(__("Failed to capture the order #{$orderId}. Please refer to Windcave module log for more details."));
            }
            
            $this->_paymentUtil->savePxPostResponse($payment, $responseXmlElement);
            
            $transactionId = (string)$responseXmlElement->DpsTxnRef; // use the DpsTxnRef of Complete
        }

        $payment->setTransactionId($transactionId);
        $payment->setIsTransactionClosed(1);
        $payment->setTransactionAdditionalInfo(\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS, $info);
    }

    public function canVoid($storeId, $payment)
    {
        $this->_logger->info(__METHOD__);

        $order = $payment->getOrder();

        $isAuth = ($payment->getAdditionalInformation("DpsTransactionType") == "Auth");
        
        $orderState = $order->getState();
        $orderStatus = $order->getStatus();
        if ($isAuth && $orderState == \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT && $orderStatus == \Windcave\Payments\Controller\PxPay2\CommonAction::STATUS_AUTHORIZED) {
            return true;
        }

        return false;
    }



    public function void(\Magento\Payment\Model\InfoInterface $payment, $storeId)
    {
        $this->_logger->info(__METHOD__);

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
        
        $isAuth = ($payment->getAdditionalInformation("DpsTransactionType") == "Auth");
        $info = $payment->getAdditionalInformation();
        
        $orderState = $order->getState();
        $orderStatus = $order->getStatus();
        if ($isAuth && $orderState == \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT && $orderStatus == \Windcave\Payments\Controller\PxPay2\CommonAction::STATUS_AUTHORIZED) {
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
            if (!$responseXmlElement->Transaction || $responseXmlElement->Transaction["success"] != "1") {
                $this->_paymentUtil->savePxPostResponse($payment, $responseXmlElement);
                $errorMessage = "Failed to void order:{$orderId}, response from Windcave: {$responseXml}";
                $this->_logger->critical(__METHOD__ . $errorMessage);
                throw new \Magento\Framework\Exception\PaymentException(__("Failed to void the order #{$orderId}. Please refer to Windcave module log for more details."));
            }
            
            $this->_paymentUtil->savePxPostResponse($payment, $responseXmlElement);
        }
    }

    public function canRefund($info)
    {
        $this->_logger->info(__METHOD__);

        $dpsTxnRefForRefund = "";
        if ($this->_paymentUtil->wasVoided($info)) {
            $canRefund = false;
        } else {
            $dpsTxnRefForRefund = $this->_paymentUtil->findDpsTxnRefForRefund($info);
        
            $canRefund = false;
            if (isset($info["CardName"]) && $info["CardName"] != "Account2Account") {
                $canRefund = !empty($dpsTxnRefForRefund);
            }
        }
        
        $this->_logger->info(__METHOD__ . " canRefund:{$canRefund} DpsTxnRefForRefund:{$dpsTxnRefForRefund}");
        return $canRefund;
    }
    
    // Mage_Sales_Model_Order_Payment::refund
    // use getInfoInstance to get object of Mage_Payment_Model_Info (Mage_Payment_Model_Info::getMethodInstance Mage_Sales_Model_Order_Payment is sub class of Mage_Payment_Model_Info)
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount, $storeId)
    {
        $this->_logger->info(__METHOD__);

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

        $responseXml = $this->_communication->refund($amount, $currency, $dpsTxnRef, $storeId);
        $responseXmlElement = simplexml_load_string($responseXml);
    
        // TODO: refund occurs inside the DB transaction. So throwing the exception triggers transaction rollback. That means that _addTransaction doesn't work.
        if (!$responseXmlElement) {
            $errorMessage = " Failed to refund order:{$orderId}, Response from Windcave: {$responseXml}";
            $this->_logger->critical(__METHOD__ . $errorMessage);

            throw new \Magento\Framework\Exception\PaymentException(__("Failed to refund the order #{$orderId}. Please refer to Windcave module log for more details."));
        }

        if (!$responseXmlElement->Transaction || $responseXmlElement->Transaction["success"] != "1") {
            $errorMessage = " Failed to refund order:{$orderId}. Response from Windcave: {$responseXml}";
            $this->_logger->critical(__METHOD__ . $errorMessage);
            
            $message = $this->getErrorMessage("Refund", $responseXmlElement, ". Please refer to Windcave module log for more details.");
            throw new \Magento\Framework\Exception\PaymentException(__($message));
        }

        $payment->setTransactionAdditionalInfo("DpsTxnRef", (string)$responseXmlElement->DpsTxnRef);
        $this->_paymentUtil->savePxPostResponse($payment, $responseXmlElement);
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
}
