<?php
namespace Windcave\Payments\Block\Customer;

class Cards extends \Magento\Framework\View\Element\Template
{
    /**
     *
     * @var \Magento\Customer\Model\Session
     */
    private $_customerSession;

    /**
     *
     * @var \Windcave\Payments\Helper\PaymentUtil
     */
    private $_paymentUtil;

    /**
     *
     * @var array[string]string
     */
    private $_savedCards;

    protected function _construct()
    {
        parent::_construct();
        
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_logger = $objectManager->get(\Windcave\Payments\Logger\DpsLogger::class);
        $this->_paymentUtil = $objectManager->get(\Windcave\Payments\Helper\PaymentUtil::class);
        $this->_customerSession = $objectManager->get(\Magento\Customer\Model\Session::class);
        $this->_logger->info(__METHOD__);
    }

    public function getCards()
    {
        $this->_logger->info(__METHOD__);
        if (empty($this->_savedCards)) {
            $this->_loadCards();
        }
        return $this->_savedCards;
    }

    private function _loadCards()
    {
        $customerId = $this->_customerSession->getCustomerId();
        $this->_logger->info(__METHOD__ . " customerId:{$customerId}");
        $itemsFromDb = $this->_paymentUtil->loadSavedCards($customerId);
        
        $this->_savedCards = [];
        foreach ($itemsFromDb as $item) {
            $maskedCardNumber = trim($item->getMaskedCardNumber());
            $cc_expdate = trim($item->getCcExpiryDate());
            if (!empty($maskedCardNumber)) { // defensive code.
                $this->_savedCards[] = [
                    "CardNumber" => $maskedCardNumber,
                    "ExpiryDate" => $cc_expdate,
                    "DeleteUrl" => $this->_createUrl($maskedCardNumber, $cc_expdate)
                ];
            }
        }
        
        return $this->_savedCards;
    }

    private function _createUrl($cardNumber, $expiryDate)
    {
        $this->_logger->info(__METHOD__ . " cardNumber:{$cardNumber} expiryDate:{$expiryDate}");
        
        $url = $this->getUrl(
            'pxpay2/customer/delete',
            [
            '_secure' => true,
            'cardNumber' => $cardNumber,
            'expiryDate' => $expiryDate
            ]
        );
        return $url;
    }
}
