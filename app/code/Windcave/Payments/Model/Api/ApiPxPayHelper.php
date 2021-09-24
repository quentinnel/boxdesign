<?php
namespace Windcave\Payments\Model\Api;

use \Magento\Framework\Exception\State\InvalidTransitionException;

class ApiPxPayHelper
{
    // http://devdocs.magento.com/guides/v2.0/extension-dev-guide/service-contracts/service-to-web-service.html

    /**
     *
     * @var \Windcave\Payments\Model\Api\ApiCommonHelper
     */
    private $_apiCommonHelper;
    
    /**
     *
     * @var \Windcave\Payments\Helper\PxPay\UrlCreator
     */
    private $_pxpayUrlCreator;
    /**
     *
     * @var \Windcave\Payments\Logger\DpsLogger
     */
    private $_logger;

    /**
     *
     * @var \Magento\Quote\Model\QuoteFactory
     */
    private $_quoteFactory;

    public function __construct(\Magento\Quote\Model\QuoteFactory $quoteFactory)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_pxpayUrlCreator = $objectManager->get("\Windcave\Payments\Helper\PxPay\UrlCreator");
        $this->_apiCommonHelper = $objectManager->get("\Windcave\Payments\Model\Api\ApiCommonHelper");
     
        $this->_quoteFactory = $quoteFactory;
        $this->_logger = $objectManager->get("\Windcave\Payments\Logger\DpsLogger");
        
        $this->_logger->info(__METHOD__);
    }

    public function createUrlForCustomer($quoteId, \Magento\Quote\Api\Data\PaymentInterface $method)
    {
        $this->_logger->info(__METHOD__. " quoteId:{$quoteId}");

        /** @var \Magento\Quote\Model\Quote $quote */
        $quote = $this->_apiCommonHelper->setPaymentForLoggedinCustomer($quoteId, $method);
        
        return $this->_createUrl($quote, null);
    }
    
    public function createUrlForGuest($cartId, $email, \Magento\Quote\Api\Data\PaymentInterface $method)
    {
        $this->_logger->info(__METHOD__. " cartId:{$cartId}");

        /** @var \Magento\Quote\Model\Quote $quote */
        $quote = $this->_apiCommonHelper->setPaymentForGuest($cartId, $email, $method);
        
        return $this->_createUrl($quote, null);
    }
    
    public function createUrl(\Magento\Sales\Model\Order $order)
    {
        $this->_logger->info(__METHOD__. " orderId:{$order->getEntityId()} quoteId:{$order->getQuoteId()}");

        $quote = $this->_quoteFactory->create()->load($order->getQuoteId());
        $orderId = $order->getRealOrderId();
        return $this->_createUrl($quote, $orderId);
    }

    private function _createUrl(\Magento\Quote\Model\Quote $quote, $orderId)
    {
        // Create pxpay redirect url.
        $url = $this->_pxpayUrlCreator->CreateUrl($quote, $orderId);
        if (!isset($url) || empty($url)) {
            $quoteId = $quote->getId();
            $this->_logger->critical(__METHOD__ . " Failed to create transaction quoteId:{$quoteId}");
            throw new InvalidTransitionException(__('Failed to create transaction.'));
        }
        
        $this->_logger->info(__METHOD__. " redirectUrl:{$url}");
        return $url;
    }
}
