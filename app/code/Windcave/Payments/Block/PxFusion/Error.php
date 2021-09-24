<?php
namespace Windcave\Payments\Block\PxFusion;

use \Magento\Framework\View\Element\Template;
use \Magento\Framework\View\Element\Template\Context;

class Error extends \Magento\Framework\View\Element\Template
{
    /**
     * @var \Magento\Checkout\Model\Session
     */
    private $_checkoutSession;
    
    public function __construct(Context $context, array $data = [])
    {
        parent::__construct($context, $data);
        
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_checkoutSession = $objectManager->get(\Magento\Checkout\Model\Session::class);
        $this->_logger = $objectManager->get(\Windcave\Payments\Logger\DpsLogger::class);
        
        $this->_logger->info(__METHOD__);
    }

    protected function _prepareLayout()
    {
        $error = $this->_checkoutSession->getPxFusionError();
        $this->_logger->info(__METHOD__ . " error:{$error}");
        $this->setError($error);
        return $this;
    }
}
