<?php
namespace Windcave\Payments\Model\PxPayIFrame;

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
     * @var \Magento\Framework\App\ObjectManager
     */
    private $_objectManager;

    /**
     *
     * @var \Windcave\Payments\Helper\PxPayIFrame\Configuration
     */
    private $_configuration;

    /**
     *
     * @var \Windcave\Payments\Block\PxPayIFrame\MerchantLogo
     */
    private $_merchantLogo;

    public function __construct(\Magento\Framework\ObjectManagerInterface $objectManager)
    {
        $this->_objectManager = $objectManager;
        $this->_configuration = $this->_objectManager->get("\Windcave\Payments\Helper\PxPayIFrame\Configuration");
        $this->_logger = $this->_objectManager->get("\Windcave\Payments\Logger\DpsLogger");
        $this->_merchantLogo = $this->_objectManager->get("\Windcave\Payments\Block\PxPayIFrame\MerchantLogo");
        $this->_logger->info(__METHOD__);
    }

    public function getConfig()
    {
        $this->_logger->info(__METHOD__);
        $session = $this->_objectManager->get('\Magento\Checkout\Model\Session');
        $quote = $session->getQuote();
        $quoteId = $quote->getId();
        $customerId = $quote->getCustomerId();
        
        $customerSession = $this->_objectManager->get("\Magento\Customer\Model\Session");
        $isRebillEnabled = ($customerSession->isLoggedIn() && $this->_configuration->getAllowRebill());
        $showCardOptions = $isRebillEnabled && !$this->_configuration->getForceA2A(); // not show card configuration when rebill is false or A2A is disabled.
        
        $iframeWidth = $this->_configuration->getIFrameWidth();
        $iframeHeight = $this->_configuration->getIFrameHeight();

        $paymentUtil = $this->_objectManager->get("\Windcave\Payments\Helper\PaymentUtil");
        
        $logos = [];
        
        for ($i = 1; $i <= 5; $i++) {
            $this->_merchantLogo->setLogoPathPrefix("merchantLogo{$i}");
            $url = $this->_merchantLogo->getLogoUrl();
            if (empty($url)) {
                continue;
            }
            $logos[] = [
                "Url" => $url,
                "Alt" => $this->_merchantLogo->getLogoAlt(),
                "Width" => $this->_merchantLogo->getLogoWidth(),
                "Height" => $this->_merchantLogo->getLogoHeight()
            ];
        }
        
        $merchantUICustomOptions = [
            'linkData' => $this->_configuration->getMerchantLinkData(),
            'logos' => $logos,
            'text' => $this->_configuration->getMerchantText()
        ];
        
        return [
            'payment' => [
                'windcave' => [
                    'pxpay2iframe' => [
                        'redirectUrl' => $paymentUtil->buildRedirectUrl($quoteId),
                        'savedCards' => $this->_loadSavedCards($customerId),
                        'isRebillEnabled' => $isRebillEnabled,
                        'showCardOptions' => $showCardOptions,
                        'merchantUICustomOptions' => $merchantUICustomOptions,
                        'iframeWidth' => $iframeWidth,
                        'iframeHeight' => $iframeHeight,
                        'placeOrderButtonTitle' => $this->_configuration->getPlaceOrderButtonTitle(),
                        'method' => \Windcave\Payments\Model\Payment::PXPAY_CODE
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
