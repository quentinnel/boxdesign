<?php
namespace Windcave\Payments\Controller\PxPay2;

use \Magento\Framework\App\Action\Context;

/***
 * This is the old version of Success redirect controller.
 * Stays here to account for outstanding PxPay sessions.
 *
 * This class will be deprecated in the next major release.
 */
class Success extends CommonActionCompat
{
    
    /**
     *
     * @var \Windcave\Payments\Logger\DpsLogger
     */
    private $_logger;

    public function __construct(Context $context)
    {
        parent::__construct($context);
        $this->_logger = $this->_objectManager->get("\Windcave\Payments\Logger\DpsLogger");
        $this->_logger->info(__METHOD__);
    }

    public function execute()
    {
        $this->_logger->info(__METHOD__);
        $this->success();
    }
}
