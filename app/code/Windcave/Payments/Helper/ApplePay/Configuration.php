<?php
namespace Windcave\Payments\Helper\ApplePay;

use \Magento\Framework\App\Helper\AbstractHelper;
use \Magento\Framework\App\Helper\Context;

class Configuration extends AbstractHelper
{
    const APPLEPAY_PATH = "payment/windcave_applepay/";
    const MODULE_NAME = "Windcave_Payments";

    public function __construct(Context $context)
    {
        parent::__construct($context);
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_moduleList = $objectManager->get("Magento\Framework\Module\ModuleListInterface");
        $this->_productMetadata = $objectManager->get("Magento\Framework\App\ProductMetadataInterface");
        $this->_logger = $objectManager->get("Windcave\Payments\Logger\DpsLogger");
    }

    /**
     *
     * @var \Magento\Framework\Module\ModuleListInterface
     */
    private $_moduleList;

    /**
     *
     * @var \Magento\Framework\App\ProductMetadataInterface
     */
    private $_productMetadata;

    public function getModuleVersion()
    {
        $version = "unknown";
        if ($this->_productMetadata != null) {
            $version = $this->_productMetadata->getVersion();
        }

        if ($this->_moduleList == null) {
            return "M2:" . $version. " ext:unknown";
        }
        return "M2:" . $version . " ext:" . $this->_moduleList->getOne(self::MODULE_NAME)['setup_version'];
    }

    public function isValidForApplePay($storeId = null)
    {
        $success = true;
        $len = strlen($this->getApiUserName($storeId));
        if ($len < 1 || $len > 32) {
            $this->_logger->warn(__METHOD__ . " Invalid API Username");
            $success = false;
        }

        $len = strlen($this->getApiKey($storeId));
        if ($len < 1 || $len > 64) {
            $this->_logger->warn(__METHOD__ . " Invalid API Key");
            $success = false;
        }

        return $success;
    }

    /* We need PxPost for Capture, Refund and Void transaction */
    public function isValidForPxPost($storeId = null)
    {
        $success = true;
        $len = strlen($this->getPxPostUsername($storeId));
        if ($len < 1 || $len > 27) {
            $this->_logger->warn(__METHOD__ . " Invalid PxPost Username");
            $success = false;
        }

        $len = strlen($this->getPxPostPassword($storeId));
        if ($len < 1) {
            $this->_logger->warn(__METHOD__ . " Invalid PxPost password");
            $success = false;
        }

        if (filter_var($this->getPxPostUrl($storeId), FILTER_VALIDATE_URL) === false) {
            $this->_logger->warn(__METHOD__ . " Invalid PxPost URL");
            $success = false;
        }

        return $success;
    }

    public function getEnabled($storeId = null)
    {
        return filter_var($this->_getApplePayStoreConfig("active", $storeId), FILTER_VALIDATE_BOOLEAN);
    }

    public function getApiUserName($storeId = null)
    {
        return $this->_getApplePayStoreConfig("apiusername", $storeId);
    }

    public function getApiKey($storeId = null)
    {
        return $this->_getApplePayStoreConfig("apikey", $storeId, true);
    }

    public function getApiUrl($storeId = null)
    {
        return $this->_getApplePayStoreConfig("apiurl", $storeId, true);
    }

    public function getPxPostUsername($storeId = null)
    {
        return $this->_getApplePayStoreConfig("pxpostusername", $storeId);
    }
    
    public function getPxPostPassword($storeId = null)
    {
        return $this->_getApplePayStoreConfig("pxpostpassword", $storeId, true);
    }
    
    public function getPxPostUrl($storeId = null)
    {
        return $this->_getApplePayStoreConfig("pxposturl", $storeId);
    }

    public function getPaymentButtonType($storeId = null)
    {
        return (string)$this->_getApplePayStoreConfig("buttontype", $storeId);
    }

    public function getPaymentButtonColor($storeId = null)
    {
        return (string)$this->_getApplePayStoreConfig("buttoncolor", $storeId);
    }

    public function getMerchantName($storeId = null)
    {
        return (string)$this->_getApplePayStoreConfig("merchname", $storeId);
    }
    
    public function getSupportedNetworks($storeId = null)
    {
        return (string)$this->_getApplePayStoreConfig("supportednetwork1", $storeId);
    }

    public function getMerchantIdentifier($storeId = null)
    {
        return (string)$this->_getApplePayStoreConfig("merchid", $storeId);
    }

    public function getPaymentType($storeId = null)
    {
        return (string)$this->_getApplePayStoreConfig("paymenttype", $storeId);
    }

    private function _getApplePayStoreConfig($configName, $storeId = null, $isSensitiveData = false)
    {
        $this->_logger->info(__METHOD__ . " storeId:" . $storeId);
        
        $value = $this->scopeConfig->getValue(self::APPLEPAY_PATH . $configName, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
        
        if (!$isSensitiveData) {
            $this->_logger->info(__METHOD__ . " configName:{$configName} storeId:{$storeId} value:{$value}");
        } else {
            $this->_logger->info(__METHOD__ . " configName:{$configName} storeId:{$storeId} value:*****");
        }
        return $value;
    }
    /**
     * @return array
     */
    public function getRedirectOnErrorDetails($storeId = null)
    {
        return [ 'url' => 'checkout/cart', 'params' => [] ];
    }
}
