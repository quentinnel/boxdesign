<?php
namespace Windcave\Payments\Model\Api;

use \Magento\Framework\Exception\State\InvalidTransitionException;

class PxFusionManagement implements \Windcave\Payments\Api\PxFusionManagementInterface
{
    // http://devdocs.magento.com/guides/v2.0/extension-dev-guide/service-contracts/service-to-web-service.html
    /**
     *
     * @var \Magento\Quote\Model\QuoteRepository
     */
    private $_quoteRepository;
    
    /**
     * @var \Magento\Quote\Model\QuoteValidator
     */
    private $_quoteValidator;
    
    /**
     *
     * @var \Magento\Framework\Url
     */
    private $_url;
    
    /**
     *
     * @var \Magento\Quote\Model\PaymentMethodManagement
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
     *
     * @var \Windcave\Payments\Helper\PxFusion\Configuration
     */
    private $_configuration;
    
    /**
     *
     * @var \Magento\Quote\Api\BillingAddressManagementInterface
     */
    private $_billingAddressManagement;
    
    /**
     * @var \Magento\Checkout\Model\Session
     */
    private $checkoutSession;

    /**
     * @var \Magento\Framework\Controller\Result\JsonFactory
     */
    private $jsonResultFactory;

    public function __construct(
        \Magento\Quote\Api\BillingAddressManagementInterface $billingAddressManagement,
        \Windcave\Payments\Helper\PxFusion\Configuration $configuration,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Framework\Controller\Result\JsonFactory $jsonResultFactory
    ) {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_paymentMethodManagement = $objectManager->get("\Magento\Quote\Model\PaymentMethodManagement");
        $this->_quoteRepository = $objectManager->get("\Magento\Quote\Model\QuoteRepository");
        $this->_quoteValidator = $objectManager->get("\Magento\Quote\Model\QuoteValidator");
        $this->_url = $objectManager->get("\Magento\Framework\Url");
        
        $this->_configuration = $configuration;
        $this->_communication = $objectManager->get("\Windcave\Payments\Helper\PxFusion\Communication");
        $this->_logger = $objectManager->get("Windcave\Payments\Logger\DpsLogger");
     
        $this->_billingAddressManagement = $billingAddressManagement;
        $this->checkoutSession = $checkoutSession;

        $this->jsonResultFactory = $jsonResultFactory;

        $this->_logger->info(__METHOD__);
    }

    /**
     * {@inheritDoc}
     */
    public function set($cartId, \Magento\Quote\Api\Data\PaymentInterface $method, \Magento\Quote\Api\Data\AddressInterface $billingAddress = null)
    {
        $this->_logger->info(__METHOD__. " cartId:{$cartId}");

        // Preliminary checks to make sure the configuration is correct before we start the transaction
        $quote = $this->_quoteRepository->get($cartId);
        $addData = $method->getAdditionalData();
        $dpsBillingId = "";
        $storeId = $quote->getStoreId();
        $useSavedCard = filter_var($addData["useSavedCard"], FILTER_VALIDATE_BOOLEAN);
        
        if ($useSavedCard && array_key_exists("billingId", $addData)) {
            $dpsBillingId = $addData["billingId"];
        }
                            
        $isRebillCase = $useSavedCard && !empty($dpsBillingId);
        if ($isRebillCase) {
            if (!$this->_configuration->isValidForPxPost($storeId)) {
                throw new \Magento\Framework\Exception\PaymentException(__("Windcave module is misconfigured. Please check the configuration before proceeding"));
            }
        } else {
            if (!$this->_configuration->isValidForPxFusion($storeId)) {
                throw new \Magento\Framework\Exception\PaymentException(__("Windcave module is misconfigured. Please check the configuration before proceeding"));
            }
        }


        if ($billingAddress) {
            $this->_logger->info(__METHOD__. " assigning billing address");
            $this->_billingAddressManagement->assign($cartId, $billingAddress);
        }
        
        $this->_paymentMethodManagement->set($cartId, $method);

        $quote = $this->_quoteRepository->get($cartId);
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
