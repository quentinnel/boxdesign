<?php
namespace Windcave\Payments\Model\PxFusion;

use \Magento\Checkout\Model\ConfigProviderInterface;

// Invoked by Magento\Checkout\Block\Onepage::getCheckoutConfig
class ConfigProvider implements ConfigProviderInterface
{

    /**
     *
     * @var \Windcave\Payments\Logger\DpsLogger
     */
    private $_logger;

    /**
     *
     * @var \Windcave\Payments\Helper\PxFusion\Configuration
     */
    private $_configuration;

    /**
     *
     * @var \Windcave\Payments\Helper\PaymentUtil
     */
    private $_paymentUtil;
    
    /**
     *
     * @var \Windcave\Payments\Helper\PxFusion\Communication
     */
    private $_communication;

    /**
     *
     * @var \Magento\Framework\Url
     */
    private $_url;

    /**
     *
     * @var \Magento\Framework\App\ObjectManager
     */
    private $_objectManager;

    /**
     *
     * @var \Magento\Checkout\Model\Session
     */
    private $_checkoutSession;

    public function __construct(
        \Magento\Framework\ObjectManagerInterface $objectManager,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Framework\Url $url
    ) {
        $this->_objectManager = $objectManager;
        $this->_checkoutSession = $checkoutSession;
        $this->_url = $url;
        $this->_configuration = $objectManager->get("\Windcave\Payments\Helper\PxFusion\Configuration");
        $this->_communication = $objectManager->get("\Windcave\Payments\Helper\PxFusion\Communication");
        $this->_paymentUtil = $objectManager->get("\Windcave\Payments\Helper\PaymentUtil");
        $this->_logger = $objectManager->get("\Windcave\Payments\Logger\DpsLogger");
        $this->_logger->info(__METHOD__);
    }
    
    // QuoteData Magento\Checkout\Helper\Data\DefaultConfigProvider
    public function getConfig()
    {
        $this->_logger->info(__METHOD__. " quoteId: ". $this->_checkoutSession->getQuoteId());
        $quote = $this->_checkoutSession->getQuote();

        $customerSession = $this->_objectManager->get("\Magento\Customer\Model\Session");
        $isRebillEnabled = ($customerSession->isLoggedIn() && $this->_configuration->getAllowRebill());
        $showCardOptions = $isRebillEnabled; // no other conditions for rebilling on PxFusion?
        $customerId = $quote->getCustomerId();

        return [
            'payment' => [
                'windcave' => [
                    'pxfusion' => [
                        'postUrl' => $this->_configuration->getPostUrl($quote->getStoreId()),
                        'savedCards' => $this->_loadSavedCards($customerId),
                        'isRebillEnabled' => $isRebillEnabled,
                        'requireCvcForRebilling' => $this->_configuration->getRequireCvcForRebilling($quote->getStoreId()),
                        'showCardOptions' => $showCardOptions,
                        'placeOrderButtonTitle' => $this->_configuration->getPlaceOrderButtonTitle(),
                        'method' => \Windcave\Payments\Model\PxFusion\Payment::CODE,
                    ]
                ]
            ]
        ];
    }

    private function _loadSavedCards($customerId)
    {
        $this->_logger->info(__METHOD__ . " customerId:{$customerId}");
        $savedCards = [];
        
        if (!empty($customerId)) { // do not access database if the order is processed by guest, to improve performance.
            $billingModel = $this->_objectManager->create("\Windcave\Payments\Model\BillingToken");
            $billingModelCollection = $billingModel->getCollection()->addFieldToFilter('customer_id', $customerId);
            $billingModelCollection->getSelect()->group(
                [
                'masked_card_number',
                'cc_expiry_date'
                ]
            );
            
            foreach ($billingModelCollection as $item) {
                $maskedCardNumber = trim($item->getMaskedCardNumber());
                $ccExpiryDate = trim($item->getCcExpiryDate());
                if (!empty($maskedCardNumber)) {
                    $savedCards[] = [
                        "billing_token" => $item->getDpsBillingId(),
                        "card_number" => $maskedCardNumber,
                        "expiry_date" => $ccExpiryDate,
                        "card_info" => $maskedCardNumber . " Expiry Date:" . $ccExpiryDate
                    ];
                }
            }
        }
        
        return $savedCards;
    }
}
