<?php
namespace Windcave\Payments\Model\Api;

class GuestPxPayIFrameManagement implements \Windcave\Payments\Api\GuestPxPayIFrameManagementInterface
{
    // http://devdocs.magento.com/guides/v2.0/extension-dev-guide/service-contracts/service-to-web-service.html
    
        /**
         *
         * @var \Windcave\Payments\Model\Api\ApiPxPayHelper
         */
    private $_apiHelper;

    /**
     *
     * @var \Windcave\Payments\Logger\DpsLogger
     */
    private $_logger;


    /**
     *
     * @var \Windcave\Payments\Helper\PxPay\UrlCreator
     */
    private $_pxpayUrlCreator;

    /**
     * @var \Magento\Quote\Api\CartRepositoryInterface
     */
    private $_cartRepository;
    
    /**
     * @var \Magento\Quote\Model\QuoteIdMaskFactory
     */
    private $_quoteIdMaskFactory;
    
    /**
     * @var \Magento\Quote\Api\GuestBillingAddressManagementInterface
     */
    private $billingAddressManagement;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    private $checkoutSession;
    
    
    public function __construct(
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Quote\Model\QuoteIdMaskFactory $quoteIdMaskFactory,
        \Magento\Quote\Api\GuestBillingAddressManagementInterface $billingAddressManagement,
        \Magento\Checkout\Model\Session $checkoutSession
    ) {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_apiHelper = $objectManager->get("\Windcave\Payments\Model\Api\ApiPxPayHelper");
        $this->_logger = $objectManager->get("\Windcave\Payments\Logger\DpsLogger");
        $this->_logger->info(__METHOD__);
        
        $this->_cartRepository = $quoteRepository;
        $this->_quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->billingAddressManagement = $billingAddressManagement;

        $this->checkoutSession = $checkoutSession;
    }

    /**
     * {@inheritDoc}
     */
    public function set(
        $cartId,
        $email,
        \Magento\Quote\Api\Data\PaymentInterface $method,
        \Magento\Quote\Api\Data\AddressInterface $billingAddress = null
    ) {
        $this->_logger->info(__METHOD__. " cartId:{$cartId} guestEmail:{$email}");
        // Create pxpay redirect url.
        
        if ($billingAddress) {
            $this->_logger->info(__METHOD__. " assigning billing address.");
            $billingAddress->setEmail($email);
            $this->billingAddressManagement->assign($cartId, $billingAddress);
        } else {
            $quoteIdMask = $this->_quoteIdMaskFactory->create()->load($cartId, 'masked_id');
            $quoteId = $quoteIdMask->getQuoteId();
            $this->_cartRepository->getActive($quoteId)->getBillingAddress()->setEmail($email);
        }
        
        $url = $this->_apiHelper->createUrlForGuest($cartId, $email, $method);
        
        $this->_logger->info(__METHOD__. " redirectUrl:{$url}");
        return $url;
    }

    public function getRedirectLink()
    {
        $order = $this->checkoutSession->getLastRealOrder();
        $this->_logger->info(__METHOD__. " orderId:{$order->getEntityId()}");

        $payment = $order->getPayment();
        $methodInstance = $payment->getMethodInstance();
        $infoInstance = $methodInstance->getInfoInstance();

        $additionalInfo = $payment->getAdditionalInformation();
        $pxPayUrl = $additionalInfo["PxPayHPPUrl"];

        if (!$pxPayUrl) {
            $this->_logger->info(__METHOD__. " orderId:{$order->getEntityId()} no url. How come?!");
            return "";
        } else {
            $this->_logger->info(__METHOD__. " orderId:{$order->getEntityId()} existingURL:{$pxPayUrl}");
        }
        return $pxPayUrl;
    }
}
