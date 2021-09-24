<?php
namespace Windcave\Payments\Helper;

use \Magento\Framework\App\Helper\AbstractHelper;
use \Magento\Framework\App\Helper\Context;
use \Magento\Customer\Api\CustomerRepositoryInterface;
use \Magento\Customer\Api\AccountManagementInterface;
    
class PaymentUtil extends AbstractHelper
{

    /**
     *
     * @var \Magento\Framework\App\ObjectManager
     */
    private $_objectManager;

    /**
     * Asset service
     *
     * @var \Magento\Framework\View\Asset\Repository
     */
    private $_assetRepo;

    /**
     * Timezone interface
     *
     * @var \Magento\Framework\Stdlib\DateTime\TimezoneInterface
     */
    private $_timezone;

    /**
     * @var \Magento\Framework\Serialize\Serializer\Json
     */
    private $_json;

    /**
     * @var \Magento\Framework\Serialize\Serializer\Serialize
     */
    private $_serialize;

    public function __construct(Context $context)
    {
        parent::__construct($context);
        $this->_objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_logger = $this->_objectManager->get("\Windcave\Payments\Logger\DpsLogger");
        $this->_assetRepo = $this->_objectManager->get("\Magento\Framework\View\Asset\Repository");
        $this->_timezone = $this->_objectManager->get("\Magento\Framework\Stdlib\DateTime\TimezoneInterface");
        $this->_json = $this->_objectManager->get(\Magento\Framework\Serialize\Serializer\Json::class);
        $this->_serialize = $this->_objectManager->get(\Magento\Framework\Serialize\Serializer\Serialize::class);
        $this->_logger->info(__METHOD__);
    }

    public function buildRedirectUrl()
    {
        $this->_logger->info(__METHOD__);
        $urlManager = $this->_objectManager->get('\Magento\Framework\Url');
        $url = $urlManager->getUrl('pxpay2/order/redirect', ['_secure' => true]);
        
        $this->_logger->info(__METHOD__ . " url: {$url} ");
        return $url;
    }

    private function getTimeStr()
    {
        $date = $this->_timezone->date();
        return $date->format("Y-m-d H:i:s");
    }

    public function saveInvalidResponse($payment, $responseText)
    {
        $this->_logger->info(__METHOD__ . " responseText:{$responseText}");
        $info = [];
        $info["Error"] = $responseText;
        $payment->setAdditionalInformation($this->getTimeStr(), json_encode($info));
        $payment->save();
        return $info;
    }

    public function savePxPostResponse($payment, $responseXmlElement)
    {
        $this->_logger->info(__METHOD__);
        $info = [];
        $transactionXmlElement = $responseXmlElement->Transaction;
        if ($transactionXmlElement) {
            $info["DpsTransactionType"] = (string)$transactionXmlElement->TxnType;
            $info["ReCo"] = (string)$transactionXmlElement->ReCo;
        } else {
            $info["ReCo"] = (string)$responseXmlElement->ReCo;
        }
        $info["DpsTxnRef"] = (string)$responseXmlElement->DpsTxnRef;
        $info["DpsResponseText"] = (string)$responseXmlElement->ResponseText;
        $responseCardName = (string)$responseXmlElement->CardName;
        if (!empty($responseCardName)) {
            // Complete/Refund does not provide this field, and we don't want to show empty field
            $info["CardName"] = (string)$responseXmlElement->CardName;
        }
        
        $payment->setAdditionalInformation($this->getTimeStr(), json_encode($info));
        $payment->save();
        
        return $info;
    }

    public function formatCurrency($amount, $currencyCode)
    {
        $exponents = [
            "BYR" => 0,
            "XOF" => 0,
            "XOF" => 0,
            "BIF" => 0,
            "XAF" => 0,
            "XAF" => 0,
            "XAF" => 0,
            "KMF" => 0,
            "XAF" => 0,
            "XOF" => 0,
            "DJF" => 0,
            "XAF" => 0,
            "XPF" => 0,
            "XAF" => 0,
            "GNF" => 0,
            "JPY" => 0,
            "KRW" => 0,
            "XOF" => 0,
            "XPF" => 0,
            "XOF" => 0,
            "PYG" => 0,
            "RWF" => 0,
            "XOF" => 0,
            "XOF" => 0,
            "VUV" => 0,
            "XPF" => 0,
            "BHD" => 3,
            "IQD" => 3,
            "JOD" => 3,
            "KWD" => 3,
            "LYD" => 3,
            "OMR" => 3,
            "TND" => 3
        ];
        $exponent = 2;
        if (array_key_exists($currencyCode, $exponents)) {
            $exponent = $exponents[$currencyCode];
        }
        
        $formatedAmount = number_format($amount, $exponent, ".", '');
        
        $this->_logger->info(__METHOD__ . " from:{$amount} to: {$formatedAmount}  Currency:{$currencyCode}");
        return $formatedAmount;
    }

    public function loadOrderById($orderId)
    {
        $this->_logger->info(__METHOD__ . " orderId:{$orderId}");
        
        $orderManager = $this->_objectManager->get('Magento\Sales\Model\Order');
        $order = $orderManager->loadByAttribute("entity_id", $orderId);
        $orderIncrementId = $order->getIncrementId();
        $this->_logger->info(__METHOD__ . " orderIncrementId:{$orderIncrementId}");
        if (!isset($orderIncrementId)) {
            return null;
        }
        return $order;
    }

    public function buildPxPayRequestData($order, $transactionType, $forceA2A)
    {
        $orderIncrementId = $order->getIncrementId();
        $this->_logger->info(__METHOD__ . " orderIncrementId:{$orderIncrementId} transactionType:{$transactionType} forceA2A:{$forceA2A}");
        
        $currency = $order->getOrderCurrencyCode();
        $amount = $order->getBaseGrandTotal();
        
        $additionalInfo = [];
        $useSavedCard = false;
        $dpsBillingId = "";
        $enableAddBillCard = false;

        $payment = $order->getPayment();
        $additionalInfo = $payment->getAdditionalInformation();
        
        $useSavedCard = filter_var($additionalInfo["UseSavedCard"], FILTER_VALIDATE_BOOLEAN);
        if ($useSavedCard) {
            $dpsBillingId = $additionalInfo["DpsBillingId"];
        }
        $enableAddBillCard = $additionalInfo["EnableAddBillCard"];
        
        $dataBag = $this->_objectManager->create("\Magento\Framework\DataObject");
        $dataBag->setForceA2A(false);
        if ($transactionType == "Purchase" && $forceA2A) {
            $dataBag->setForceA2A(true);
        }
        
        // <TxnId>ABC123</TxnId>
        // <TxnData1>John Doe</TxnData1>
        // <TxnData2>0211111111</TxnData2>
        // <TxnData3>98 Anzac Ave, Auckland 1010</TxnData3>
        
        $dataBag->setAmount($amount);
        $dataBag->setCurrency($currency);
        $dataBag->setTransactionType($transactionType);
        $dataBag->setOrderIncrementId($orderIncrementId);
        $dataBag->setOrderId($order->getId());
        $dataBag->setDpsBillingId($dpsBillingId);
        $dataBag->setEnableAddBillCard($enableAddBillCard);
        
        $customerInfo = $this->loadCustomerInfo($order);
        $dataBag->setCustomerInfo($customerInfo);
        
        $this->_logger->info(__METHOD__ . " dataBag:" . var_export($dataBag, true));
        return $dataBag;
    }

    public function loadCustomerInfo($order)
    {
        $customerId = $order->getCustomerId();
        $this->_logger->info(__METHOD__ . " customerId:{$customerId}");
        $customerInfo = $this->_objectManager->create("\Magento\Framework\DataObject");
        
        $customerInfo->setId($customerId);
        
        $customerInfo->setName($order->getCustomerName());
        $customerInfo->setEmail($order->getCustomerEmail());
        
        try {
            $address = $order->getBillingAddress();
            if ($address) {
                $customerInfo->setPhoneNumber($address->getTelephone());
                
                $streetFull = implode(" ", $address->getStreet()) . " " . $address->getCity() . ", " . $address->getRegion() . " " . $address->getPostcode() . " " . $address->getCountryId();
                
                $customerInfo->setAddress($streetFull);
            }
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $this->_logger->critical($e->_toString());
        }
        
        return $customerInfo;
    }

    public function loadSavedCards($customerId)
    {
        $this->_logger->info(__METHOD__ . " customerId:{$customerId}");
        
        $billingModel = $this->_objectManager->create("\Windcave\Payments\Model\BillingToken");
        
        $billingModelCollection = $billingModel->getCollection()->addFieldToFilter('customer_id', $customerId);
        
        $billingModelCollection->getSelect()->group(['masked_card_number', 'cc_expiry_date']);
        return $billingModelCollection;
    }

    public function deleteCards($customerId, $cardNumber, $expiryDate)
    {
        $this->_logger->info(__METHOD__ . " customerId:{$customerId} cardNumber:{$cardNumber} expiryDate:{$expiryDate}");
        
        $billingModel = $this->_objectManager->create("\Windcave\Payments\Model\BillingToken");
        
        $billingModelCollection = $billingModel->getCollection()
            ->addFieldToFilter('customer_id', $customerId)
            ->addFieldToFilter('masked_card_number', $cardNumber)
            ->addFieldToFilter('cc_expiry_date', $expiryDate);
        $billingModelCollection->walk('delete');
    }

    public function wasVoided($info)
    {
        $this->_logger->info(__METHOD__);
        if (isset($info["DpsTransactionType"])) {
            
            if ($info["DpsTransactionType"] == "Void" && $this->isSuccessfulTransaction($info)) {
                return true;
            }
            
            foreach ($info as $key => $value) {
                if (strtotime($key)) {
                    $decodedValue;

                    try {
                        $decodedValue = $this->_json->unserialize($value);
                    } catch (\Exception $e) {
                        // TODO: deprecate unserialize completely
                        $decodedValue = $this->_serialize->unserialize($value);
                    }

                    if (!empty($decodedValue) && $decodedValue["DpsTransactionType"] == "Void" && $this->isSuccessfulTransaction($info)) {
                        $this->_logger->info(__METHOD__ . ": Yes");
                        return true;
                    }
                }
            }
        }
        $this->_logger->info(__METHOD__ . ": No");
        return false;
    }

    private function isSuccessfulTransaction($info)
    {
        if (isset($info["ReCo"])) {
            return $info["ReCo"] == "00";  // This is not ideal. Should not check the ReCo
        }
        return false;
    }

    public function findDpsTxnRefForRefund($info)
    {
        $this->_logger->info(__METHOD__);
        $dpsTxnRef = "";
        if (isset($info["DpsTransactionType"])) {
            
            if ($info["DpsTransactionType"] == "Purchase" && $this->isSuccessfulTransaction($info)) {
                $dpsTxnRef = $info["DpsTxnRef"];
                $this->_logger->info(__METHOD__ . " DpsTransactionType:Purchase DpsTxnRef: {$dpsTxnRef}");
                return $dpsTxnRef;
            }
            
            foreach ($info as $key => $value) {
                if (strtotime($key)) {

                    $decodedValue;

                    try {
                        $decodedValue = $this->_json->unserialize($value);
                    } catch (\Exception $e) {
                        // TODO: deprecate unserialize completely
                        $decodedValue = $this->_serialize->unserialize($value);
                    }

                    if (!empty($decodedValue) && $decodedValue["DpsTransactionType"] == "Complete" && $this->isSuccessfulTransaction($decodedValue)) {
                        $dpsTxnRef = $decodedValue["DpsTxnRef"];
                        $this->_logger->info(__METHOD__ . " DpsTransactionType:Complete  DpsTxnRef: {$dpsTxnRef}");
                        return $dpsTxnRef;
                    }
                }
            }
        }
        $this->_logger->info(__METHOD__ . " DpsTxnRef not found");
        return $dpsTxnRef;
    }
}
