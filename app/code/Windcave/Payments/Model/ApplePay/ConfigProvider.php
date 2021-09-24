<?php
namespace Windcave\Payments\Model\ApplePay;

use \Magento\Checkout\Model\ConfigProviderInterface;

// Invoked by Magento\Checkout\Block\Onepage::getCheckoutConfig
class ConfigProvider implements ConfigProviderInterface
{
    //const CODE = 'windcave_applepay';
    /**
     *
     * @var \Windcave\Payments\Logger\DpsLogger
     */
    private $_logger;

    /**
     *
     * @var \Windcave\Payments\Helper\ApplePay\Configuration
     */
    private $_configuration;

    /**
     *
     * @var \Magento\Framework\App\ObjectManager
     */
    private $_objectManager;

    public function __construct(\Magento\Framework\ObjectManagerInterface $objectManager)
    {
        $this->_objectManager = $objectManager;
        $this->_configuration = $this->_objectManager->get("\Windcave\Payments\Helper\ApplePay\Configuration");
        $this->_logger = $this->_objectManager->get("\Windcave\Payments\Logger\DpsLogger");
        $this->_logger->info(__METHOD__);
    }

    public function getConfig()
    {
        $this->_logger->info(__METHOD__);
        return [
            'payment' => [
                'windcave' => [
                    'applepay' => [
                        'supportedNetworks' => $this->_configuration->getSupportedNetworks(),
                        'merchantIdentifier' => $this->_configuration->getMerchantIdentifier(),
                        'merchantName' => $this->_configuration->getMerchantName(),
                        'buttonType' => $this->_configuration->getPaymentButtonType(),
                        'buttonColor' => $this->_configuration->getPaymentButtonColor(),
                        'method' => \Windcave\Payments\Model\ApplePay\Payment::APPLEPAY_CODE
                    ]
                ]
            ]
        ];
    }
}
