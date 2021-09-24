<?php
namespace Windcave\Payments\Helper\ApplePay;

use \Magento\Framework\App\Helper\AbstractHelper;
use \Magento\Framework\App\Helper\Context;
use \Magento\Payment\Gateway\Http\Client\Soap;
use Magento\Catalog\Model\Product\Exception;

class Communication extends AbstractHelper
{
    // Transaction approved.
    const APPROVED = 0;
    // Transaction declined.
    const DECLINED = 1;
    // Transaction declined due to transient error (retry advised).
    const TRANSIENT_ERROR = 2;
    // Invalid data submitted in form post (alert site admin).
    const INVALID_DATA = 3;
    // Transaction result cannot be determined at this time (re-run GetTransaction).
    const RESULT_UNKOWN = 4;
    // Transaction did not proceed due to being attempted after timeout timestamp or having been cancelled by a CancelTransaction call.
    const CANCELLED = 5;
    // No transaction found (SessionId query failed to return a transaction record transaction not yet attempted).
    const NO_TRANSACTION = 6;
    // Retry from Accepted to Complete response.
    const MAX_RETRY_COUNT = 20;
     /**
      *
      * @var \Magento\Framework\Webapi\Soap\ClientFactory
      */
    private $_clientFactory;

    /**
     *
     * @var \Windcave\Payments\Helper\ApplePay\Configuration
     */
    private $_configuration;

    /**
     *
     * @var \Magento\Framework\App\ObjectManager
     */
    private $_objectManager;
    /**
     *
     * @var \Magento\Quote\Model\QuoteRepository
     */
    protected $_quoteRepository;
    /**
     *
     * @var \Windcave\Payments\Helper\PaymentUtil
     */
    private $_paymentUtil;
     /**
      *
      * @var \Windcave\Store\Model\StoreManagerInterface
      */
    private $_storeManager;
    /**
     *
     * @var \Magento\Framework\HTTP\Header
     */
    protected $_httpHeader;
    /**
     *
     * @var \Windcave\Payments\Helper\Common\PxPost
     */
    private $_pxPost;
    /**
     *
     * @var \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress
     */
    protected $_remoteIpAddress;
     /**
      * @var \Magento\Framework\Notification\NotifierInterface
      */
    private $_notifierInterface;
    /**
     *
     * @var \Magento\Framework\Message\ManagerInterface
     */
    private $_messageManager;

    public function __construct(
        Context $context,
        \Magento\Framework\Webapi\Soap\ClientFactory $clientFactory,
        \Magento\Quote\Model\QuoteRepository $quoteRepository,
        \Magento\Framework\HTTP\Header $httpHeader,
        \Magento\Framework\Notification\NotifierInterface $notifierInterface,
        \Magento\Framework\Message\ManagerInterface $messageManager
    ) {
        parent::__construct($context);
        $this->_clientFactory = $clientFactory;
        $this->_objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_logger = $this->_objectManager->get("\Windcave\Payments\Logger\DpsLogger");
        $this->_configuration = $this->_objectManager->get("\Windcave\Payments\Helper\ApplePay\Configuration");
        $this->_paymentUtil = $this->_objectManager->get("\Windcave\Payments\Helper\PaymentUtil");
        $this->_storeManager = $this->_objectManager->get("\Magento\Store\Model\StoreManagerInterface");
        $this->_pxPost = $this->_objectManager->get("\Windcave\Payments\Helper\Common\PxPost");
        $this->_quoteRepository = $quoteRepository;
        $this->_notifierInterface = $notifierInterface;
        $this->_messageManager = $messageManager;
        $this->_logger->info(__METHOD__);
    }
    /**
     *
     * @param string $validationUrl
     * @param string $domainName
     * @param string $storeId
     * @param string $return
     */
    public function startApplePaySession($validationUrl, $domainName)
    {
        $this->_logger->info(__METHOD__ . " validationUrl:{$validationUrl} domainName:{$domainName}");
        $this->_storeManager = $this->_objectManager->get("\Magento\Store\Model\StoreManagerInterface");
        $store = $this->_storeManager->getStore();
        $post_data = [
            'validationUrl' => $validationUrl,
            'displayName' => empty($this->_configuration->getMerchantName()) ? $store->getName() : $this->_configuration->getMerchantName(),
            'domainName' => $domainName
        ];
        $requestJson = json_encode($post_data, JSON_FORCE_OBJECT);
        $this->_logger->info(__METHOD__ . " requestJson:{$requestJson}");
        $apiUrl = $this->_configuration->getApiUrl($store->getId());
        $apiBase = parse_url($apiUrl, PHP_URL_SCHEME) ."://". parse_url($apiUrl, PHP_URL_HOST);
        $apiBase = $apiBase . "/applepay/validatemerchant";
        return $this->_sendApiJsonRequest($requestJson, $apiBase);
    }
    
    /**
     *
     * @param string $applePayPayment
     * @param \Magento\Quote\Model\Quote $quote
     * @param string $return
     */
    public function doCreatePaymentTransaction($additionalData, $quote)
    {
        /*https://sec.windcave.com/api/v1/transactions*/
        $this->_logger->info(__METHOD__);
        $apBase64Encoded = $additionalData["paymentData"];
        $quoteId = $quote->getQuoteId();
        $requestObj = null;
        try {
            $requestObj = $this->buildRequest($quote, $apBase64Encoded);
        } catch (\Magento\Framework\Exception\State\InvalidTransitionException $exception) {
            // TODO: need to do something here
            $this->_notifierInterface->addMajor(
                "Failed to charge the saved card.",
                " QuoteId: " . $quoteId .
                ". See Windcave extension log for more details."
            );

            throw new \Magento\Framework\Exception\LocalizedException(
                __("Internal error while processing quote #{$quoteId}. Please contact support.")
            );
        }
        $store = $this->_storeManager->getStore();
        $apiUrl = $this->_configuration->getApiUrl($store->getId()) . "/transactions";
        $requestParams = json_encode($requestObj, JSON_UNESCAPED_SLASHES);
        $completeResponse = $this->_sendApiJsonRequest($requestParams, $apiUrl);
        if ($completeResponse["httpCode"] == "202") { //accepted
            //we have to request until complete
            $endpoint = $apiUrl."/".$completeResponse["response"]["id"];
            $completeResponse = $this->handlePaymentResponse($endpoint);
        }
        $paymentInfo = [];
        if ($completeResponse["httpCode"] == "200") {
            $jsonResponse = json_encode($completeResponse);
            $objResponse = json_decode($jsonResponse);
            $paymentType = property_exists($objResponse->response, "cardname") ? $objResponse->response->cardname : $this->getCCType(substr($objResponse->response->card->cardNumber, 0, 4));
            $cardholderName = property_exists($objResponse->response->card, "cardHolderName") ? $objResponse->response->card->cardHolderName : "not specified";
            $paymentInfo = [
                "httpCode" => $objResponse->httpCode,
                "txnType" => ucfirst($objResponse->response->type),
                "currencyName" => $objResponse->response->currency,
                "DpsTxnRef" => $objResponse->response->id,
                "paymentType" => $paymentType,
                "responseCode" => $objResponse->response->reCo,
                "response" => $objResponse->response->responseText,
                "merchantRef" => $objResponse->response->merchantReference,
                "cardholderName" => $cardholderName,
                "amount" => $objResponse->response->amount
            ];
        } else {
            $this->_logger->info(__METHOD__ . " PROBLEM = " . $completeResponse["httpCode"]);
            $errorMessages = "";
            foreach ($objResponse->response->errors as $error) {
                $errorMessages .= $error->message . ". ";
            }
            $this->_logger->info(__METHOD__ . " Response Error Message: " . $errorMessages);
            $paymentInfo = [
                "httpCode" => $completeResponse["httpCode"],
                "response" => $errorMessages,
                "responseCode" => Communication::INVALID_DATA
            ];
        }
        return $paymentInfo;
    }

    private function handlePaymentResponse($endpoint)
    {
        $this->_logger->info(__METHOD__ . " endpoint: {$endpoint}");
        $response = null;
        $tried = 0;
        do {
            $tried++;
            sleep(1); // wait
            $response = $this->_sendApiJsonRequest("", $endpoint);
            $this->_logger->info(__METHOD__ . " tried: {$tried}");
        } while ($response["httpCode"] == "202" && $tried < Communication::MAX_RETRY_COUNT);
        
        if ($response["response"] == "202") {
            $this->_logger->critical(__METHOD__ . " timeout. tried:{$tried}");
            $this->_messageManager->addErrorMessage("Failed to process the order, please contact support.");
            $this->_redirect('checkout/cart');
        }
        return $response;
    }

    private function _sendApiJsonRequest($requestJson, $endpointUrl)
    {
        $this->_logger->info(__METHOD__ . " requestParams: {$requestJson} endpointUrl: {$endpointUrl}");
        $store = $this->_storeManager->getStore();
        $userName = $this->_configuration->getApiUserName($store->getId());
        $apiKey = $this->_configuration->getApiKey($store->getId());
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpointUrl);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($requestJson !== "") {
            $this->_logger->info(__METHOD__ . " POST Request");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $requestJson);
        }
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $headers = [
            'Content-Type: application/json',
            'Authorization: Basic '. base64_encode("$userName:$apiKey")
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        $httpCode = 0;
        $error = false;
        if (!curl_errno($ch)) {
            if (!$response) {
                $errorMessage = " Error:" . curl_error($ch) . " Error Code:" . curl_errno($ch);
                $this->_logger->critical(__METHOD__ . $errorMessage);
                $error = true;
            } else {
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                
                if ($httpCode && substr($httpCode, 0, 2) != "20") {
                    $errorMessage = " HTTP CODE: {$httpCode} for URL: {$endpointUrl}";
                    $this->_logger->critical(__METHOD__ . $errorMessage);
                    $error = true;
                }
            }
        }
        curl_close($ch);
        //$resCode = $httpCode; //this line makes no sense why should I assign to another variable to make it work (esp. in docreatetransaction). without this the httpCode is 0 only when inside the array
        $objResponse = [
            "response" => json_decode($response, true),
            "httpCode" => $httpCode,
            "error" => $error
        ];
        return $objResponse;
    }

    private function buildRequest(\Magento\Quote\Model\Quote $quote, $apBase64Encoded)
    {
        /* - USE THIS JSON FORMAT based on - https://sec.windcave.com/api/v1/transactions */
        $this->_logger->info(__METHOD__);
        $remote = $this->_objectManager->get('Magento\Framework\HTTP\PhpEnvironment\RemoteAddress');
        $header = $this->_objectManager->get('\Magento\Framework\HTTP\Header');
        $applePayPayment = base64_decode($apBase64Encoded);

        $currency = $quote->getBaseCurrencyCode();
        $amount = $this->_paymentUtil->formatCurrency($quote->getBaseGrandTotal(), $currency);
        $merchantRef = $quote->getReservedOrderId();
        $ipAddress = $remote->getRemoteAddress();
        $userAgent = $header->getHttpUserAgent();
        $store = $this->_storeManager->getStore();
        $obj = [
            "type" => strtolower($this->_configuration->getPaymentType($store->getId())),
            "method" => "applePay",
            "amount" => $amount,
            "currency" => $currency,
            "clientType" => "internet",
            "merchantReference" => $merchantRef,
            "applePay" => json_decode($applePayPayment),
            "browser" => [
                "ipAddress" => $ipAddress,
                "userAgent" => $userAgent
            ],
            "notificationUrl" => "replace-me"
        ];
        return $obj;
    }

    public function void($dpsTxnRef, $storeId)
    {
        $this->_logger->info(__METHOD__);
        return $this->_sendPxPostRequest(0, 0, "Void", $dpsTxnRef, $storeId);
    }
    public function refund($amount, $currency, $dpsTxnRef, $storeId)
    {
        $this->_logger->info(__METHOD__);
        return $this->_sendPxPostRequest($amount, $currency, "Refund", $dpsTxnRef, $storeId);
    }
    
    public function complete($amount, $currency, $dpsTxnRef, $storeId)
    {
        $this->_logger->info(__METHOD__);
        return $this->_sendPxPostRequest($amount, $currency, "Complete", $dpsTxnRef, $storeId);
    }
    
    private function _sendPxPostRequest($amount, $currency, $txnType, $dpsTxnRef, $storeId)
    {
        $this->_logger->info(__METHOD__ . " amount:{$amount} currency:{$currency} txnType:{$txnType} dpsTxnRef:{$dpsTxnRef} storeId:{$storeId}");
        
        $dataBag = $this->_objectManager->create("\Magento\Framework\DataObject");
        
        $dataBag->setUsername($this->_configuration->getPxPostUsername($storeId));
        $dataBag->setPassword($this->_configuration->getPxPostPassword($storeId));
        $dataBag->setPostUrl($this->_configuration->getPxPostUrl($storeId));
        if ($txnType !== "Void") {
            $formattedAmount = $this->_paymentUtil->formatCurrency($amount, $currency);
            $dataBag->setAmount($formattedAmount);
            $dataBag->setCurrency($currency);
        }
        $dataBag->setDpsTxnRef($dpsTxnRef);
        $dataBag->setTxnType($txnType);
        
        return $this->_pxPost->send($dataBag);
    }

    private function getCCType($cardNumber)
    {
        // Remove non-digits from the number
        $cardNumber = preg_replace('/\D/', '', $cardNumber);
        //Regex based on Worldpay
        //to verify regex - https://www.phpliveregex.com/
        switch ($cardNumber) {
            case (preg_match('/^4/', $cardNumber) >= 1):
                return 'VISA-SSL';
            //Updated for Mastercard 2017 BINs expansion
            case (preg_match('/^(5[1-5][0-9]{14}|2(22[1-9][0-9]{12}|2[3-9][0-9]{13}|[3-6][0-9]{14}|7[0-1][0-9]{13}|720[0-9]{12}))$/', $cardNumber) >= 1):
                return 'ECMC-SSL';
            case (preg_match('/^3[47]/', $cardNumber) >= 1):
                return 'AMEX-SSL';
            case (preg_match('/^36|30[0-5]/', $cardNumber) >= 1):
                return 'DINERS-SSL';
            case (preg_match('/^(6011|622(12[6-9]|1[3-9][0-9]|[2-8][0-9]{2}|9[0-1][0-9]|92[0-5]|64[4-9])|65)/', $cardNumber) >= 1):
                return 'DISCOVER-SSL';
            case (preg_match('/^35(2[89]|[3-8][0-9])/', $cardNumber) >= 1):
                return 'JCB-SSL';
            case (preg_match('/^(4026|417500|4508|4844|491(3|7))/', $cardNumber) >= 1):
                return 'VISA-SET';
            case (preg_match('/^62|88/', $cardNumber) >= 1):
                return 'CHINAUNIONPAY-SSL';
            default:
                return ' Unknown';
        }
    }
}
