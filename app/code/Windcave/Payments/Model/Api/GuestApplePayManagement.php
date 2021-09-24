<?php
namespace Windcave\Payments\Model\Api;

class GuestApplePayManagement implements \Windcave\Payments\Api\GuestApplePayManagementInterface
{
    // http://devdocs.magento.com/guides/v2.0/extension-dev-guide/service-contracts/service-to-web-service.html

    /**
     *
     * @var \Magento\Framework\App\ObjectManager
     */
    private $_objectManager;

    protected $_quoteIdMaskFactory;

    /**
     *
     * @var \Windcave\Payments\Helper\ApplePay\Communication
     */
    private $_communication;

    /**
     *
     * @var \Magento\Quote\Model\QuoteRepository
     */
    private $_quoteRepository;

    /**
     *
     * @var \Windcave\Payments\Helper\PxFusion\Configuration
     */
    private $_configuration;

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
     * @param \Windcave\Payments\Helper\ApplePay\Configuration $configuration
     */
    public function __construct(
        \Windcave\Payments\Helper\ApplePay\Configuration $configuration
    ) {
        $this->_objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_logger = $this->_objectManager->get("\Windcave\Payments\Logger\DpsLogger");
        $this->_logger->info(__METHOD__);

        $this->_paymentMethodManagement = $this->_objectManager->get("\Magento\Quote\Model\GuestCart\GuestPaymentMethodManagement");
        $this->_quoteIdMaskFactory = $this->_objectManager->get("Magento\Quote\Model\QuoteIdMaskFactory");
        $this->_communication = $this->_objectManager->get("\Windcave\Payments\Helper\ApplePay\Communication");
        $this->_quoteRepository = $this->_objectManager->get("\Magento\Quote\Model\QuoteRepository");
        $this->_configuration = $configuration;
    }

    /**
     * Validate the merchant and return the validationdata as string
     *
     * @param string $validationUrl.
     * @param string $domainName
     * @return string result
     */
    public function performValidation($validationUrl, $domainName)
    {
        $this->_logger->info(__METHOD__ . " validationUrl:{$validationUrl} domainName:{$domainName}");
        $response = $this->_communication->startApplePaySession($validationUrl, $domainName);
        return json_encode($response);
    }

    //\Magento\Quote\Api\Data\AddressInterface $billingAddress
    /**
     * Send the payment to the gateway and return the status
     *
     * @param string cartId
     * @param string email
     * @param string token
     * @param \Magento\Quote\Api\Data\PaymentInterface method
     * @param string $billingAddress
     * @return string result
     */
    public function performPayment(
        $cartId,
        $email,
        $token,
        \Magento\Quote\Api\Data\PaymentInterface $method,
        $billingAddress = null
    ) {
        //use the quote data
        $this->_logger->info(__METHOD__ . " cartId: {$cartId}");
        $this->_paymentMethodManagement->set($cartId, $method); //this set invoked /Payment/assignData
        $quoteIdMask = $this->_quoteIdMaskFactory->create()->load($cartId, 'masked_id');
        $quoteId = $quoteIdMask->getQuoteId();
        $quote = $this->_quoteRepository->get($quoteId);

        $quote->getBillingAddress()->setEmail($email);

        if (!$this->_configuration->isValidForApplePay($quote->getStoreId())) {
            throw new \Magento\Framework\Exception\PaymentException(__("Windcave module is misconfigured. Please check the configuration before proceeding"));
        }
        $quote->setCheckoutMethod(\Magento\Quote\Api\CartManagementInterface::METHOD_GUEST);
        $this->_quoteRepository->save($quote);

        $applePayCommonManagementHelper = $this->_objectManager->get("\Windcave\Payments\Model\Api\ApplePayCommonManagementHelper");
        return $applePayCommonManagementHelper->createTransaction($method, $quote);
    }
}
