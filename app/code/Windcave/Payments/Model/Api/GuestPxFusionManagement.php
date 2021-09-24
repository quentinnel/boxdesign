<?php
namespace Windcave\Payments\Model\Api;

use \Magento\Framework\Exception\State\InvalidTransitionException;

class GuestPxFusionManagement implements \Windcave\Payments\Api\GuestPxFusionManagementInterface
{
    // http://devdocs.magento.com/guides/v2.0/extension-dev-guide/service-contracts/service-to-web-service.html
    
    protected $_quoteIdMaskFactory;
    
    /**
     * @var \Magento\Quote\Model\QuoteValidator
     */
    private $_quoteValidator;
    
    /**
     *
     * @var \Magento\Quote\Model\QuoteRepository
     */
    private $_quoteRepository;
    
    /**
     *
     * @var \Magento\Framework\Url
     */
    private $_url;
    
    /**
     *
     * @var \Magento\Quote\Model\GuestCart\GuestPaymentMethodManagement
     */
    private $_paymentMethodManagement;

    /**
     *
     * @var \Windcave\Payments\Logger\DpsLogger
     */
    private $_logger;

    /**
     *
     * @var \Windcave\Payments\Helper\PxFusion\Communication
     */
    private $_communication;

    /**
     * @var \Magento\Quote\Api\GuestBillingAddressManagementInterface
     */
    private $billingAddressManagement;
    
    /**
     * @var \Magento\Checkout\Model\Session
     */
    private $checkoutSession;


    public function __construct(
        \Magento\Quote\Api\GuestBillingAddressManagementInterface $billingAddressManagement,
        \Magento\Checkout\Model\Session $checkoutSession
    ) {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_paymentMethodManagement = $objectManager->get("\Magento\Quote\Model\GuestCart\GuestPaymentMethodManagement");
        $this->_quoteIdMaskFactory = $objectManager->get("Magento\Quote\Model\QuoteIdMaskFactory");
        $this->_quoteValidator = $objectManager->get("\Magento\Quote\Model\QuoteValidator");
        $this->_quoteRepository = $objectManager->get("\Magento\Quote\Model\QuoteRepository");
        $this->_url = $objectManager->get("\Magento\Framework\Url");
        
        $this->_communication = $objectManager->get("\Windcave\Payments\Helper\PxFusion\Communication");
        $this->_logger = $objectManager->get("\Windcave\Payments\Logger\DpsLogger");
        
        $this->billingAddressManagement = $billingAddressManagement;
        
        $this->checkoutSession = $checkoutSession;

        $this->_logger->info(__METHOD__);
    }

    //set Function is not being used anymore
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
        $this->_paymentMethodManagement->set($cartId, $method);
        
        $quoteIdMask = $this->_quoteIdMaskFactory->create()->load($cartId, 'masked_id');
        $quoteId = $quoteIdMask->getQuoteId();
        
        if ($billingAddress) {
            $this->_logger->info(__METHOD__. " assigning billing address");
            
            $billingAddress->setEmail($email);
            $this->billingAddressManagement->assign($cartId, $billingAddress);

        } else {
            $this->_quoteRepository->getActive($quoteId)->getBillingAddress()->setEmail($email);
        }
        
        $quote = $this->_quoteRepository->get($quoteId);
        
        $quote->setCheckoutMethod(\Magento\Quote\Api\CartManagementInterface::METHOD_GUEST);
        // $quote->reserveOrderId();
        $this->_quoteRepository->save($quote);
        
        $this->_quoteValidator->validateBeforeSubmit($quote); // ensure all the data is correct
        
        return "";
    }

    /**
     * Returns PxFusion session data stored with the last created order.
     *
     * @return string
     */
    public function getFusionSession()
    {
        $order = $this->checkoutSession->getLastRealOrder();
        $this->_logger->info(__METHOD__. " orderId:{$order->getEntityId()}");

        $payment = $order->getPayment();

        $additionalInfo = $payment->getAdditionalInformation();
        $sessionData = $additionalInfo["PxFusionData"];

        if (!$sessionData) {
            $this->_logger->info(__METHOD__. " orderId:{$order->getEntityId()} no session data. How come?!");
            return json_encode([]);
        } else {
            $this->_logger->info(__METHOD__. " orderId:{$order->getEntityId()} sessionData:" . var_export($sessionData, true));
        }

        return json_encode($sessionData);
    }
}
