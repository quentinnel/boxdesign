<?php
namespace Windcave\Payments\Block;

use \Magento\Framework\View\Element\Template\Context;

class Info extends \Magento\Payment\Block\Info
{

    /**
     *
     * @var string
     */
    protected $_template = 'Windcave_Payments::info/default.phtml';

    /**
     * @var \Magento\Framework\Serialize\Serializer\Json
     */
    private $_json;

    /**
     * @var \Magento\Framework\Serialize\Serializer\Serialize
     */
    private $_serialize;

    public function __construct(Context $context, array $data = [])
    {
        parent::__construct($context, $data);
        
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        $this->_json = $objectManager->get(\Magento\Framework\Serialize\Serializer\Json::class);
        $this->_serialize = $objectManager->get(\Magento\Framework\Serialize\Serializer\Serialize::class);

        $this->_logger = $objectManager->get(\Windcave\Payments\Logger\DpsLogger::class);
        $this->_logger->info(__METHOD__);
    }

    protected function _prepareSpecificInformation($transport = null)
    {
        $this->_logger->info(__METHOD__);
        if (null !== $this->_paymentSpecificInformation) {
            return $this->_paymentSpecificInformation;
        }

        $data = $this->getInfo()->getAdditionalInformation();
        $decodedData = [];
        foreach ($data as $key => $value) {
            if (strtotime($key)) {
                $decodedValue;
                try {
                    $decodedValue = $this->_json->unserialize($value);
                } catch (\Exception $e) {
                    // TODO: deprecate unserialize completely
                    $decodedValue = $this->_serialize->unserialize($value);
                }
                
                if (!empty($decodedValue)) {
                    $decodedData[$key] = $decodedValue;
                }
            } elseif ($key !== "PxPayHPPUrl") {
                // We don't want to display the URL in the admin panel
                $decodedData[$key] = $value;
            }
        }
        
        $transport = parent::_prepareSpecificInformation($transport);

        unset($decodedData["Currency"]);
        $this->_paymentSpecificInformation = $transport->setData(array_merge($decodedData, $transport->getData()));

        return $this->_paymentSpecificInformation;
    }

    public function getPxPayUrl()
    {
        $data = $this->getInfo()->getAdditionalInformation();
        if (array_key_exists("PxPayHPPUrl", $data)) {
            return $data["PxPayHPPUrl"];
        }
        return null;
    }
}
