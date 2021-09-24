<?php
namespace Windcave\Payments\Controller\PxFusion;

// TODO: move the common code out.
class AdminResult extends \Windcave\Payments\Controller\PxFusion\CommonAction
{
    /**
     *
     * @var \Magento\Quote\Model\QuoteRepository
     */
    private $_quoteRepository;

    /**
     *
     * @var \Magento\Framework\Controller\Result\JsonFactory
     */
    protected $_resultJsonFactory;

    /**
     * @var \Magento\Framework\Stdlib\CookieManagerInterface
     */
    //protected $_cookieManager;

    /**
     * @var \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory CookieMetadataFactory
     */
    //private $_cookieMetadataFactory;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Sales\Model\Order\Status\HistoryFactory $orderHistoryFactory,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $txnBuilder,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Framework\Notification\NotifierInterface $notifierInterface
    ) {
        parent::__construct(
            $context,
            $orderRepository,
            $orderHistoryFactory,
            $checkoutSession,
            $quoteFactory,
            $txnBuilder,
            $searchCriteriaBuilder,
            $orderSender,
            $notifierInterface
        );

        $this->_resultJsonFactory = $this->_objectManager->get(\Magento\Framework\Controller\Result\JsonFactory::class);
        $this->_logger = $this->_objectManager->get(\Windcave\Payments\Logger\DpsLogger::class);
        $this->_configuration = $this->_objectManager->get(\Windcave\Payments\Helper\PxFusion\Configuration::class);
        $this->_quoteRepository = $this->_objectManager->get(\Magento\Quote\Model\QuoteRepository::class);
        $this->_logger->info(__METHOD__);
    }

    public function execute()
    {
        $transactionId = $this->getRequest()->getParam('sessionid');
        $this->_logger->info(__METHOD__ . " transactionId:{$transactionId}");
        return $this->_processPaymentResult($transactionId);
    }

    private function _processPaymentResult($transactionId)
    {
        $userName = $this->_configuration->getUserName();
        $this->_logger->info(__METHOD__ . " userName:{$userName} transactionId:{$transactionId}");
        
        $dataBag = $this->_loadTransactionStatusFromCache($userName, $transactionId);
        $orderIncrementId = $dataBag->getOrderIncrementId();

        $errorText = "Payment failed. Error: ";
        if (empty($orderIncrementId)) {
            $transactionResult = $this->_getTransactionStatus($transactionId, 0);
            $status = $transactionResult["status"];
            if ($status === self::APPROVED) {
                $quoteId = $transactionResult["txnRef"];
                $quote = $this->_quoteRepository->get($quoteId);
                $payment = $quote->getPayment();

                $this->_savePaymentInfoForSuccessfulPayment($payment, $transactionResult);
                $this->_savePaymentResult($userName, $transactionId, $quote, $transactionResult);

                $errorText = $errorText.
                            " ReCo:" .
                            $transactionResult["responseCode"] .
                            " ResponseText:" .
                            $transactionResult["responseText"];
            }
            
        } else {
            $transactionResult = $dataBag->getPaymentResult();
            $status = $transactionResult["status"];
        }
        
        $success = false;

        if ($status != self::NO_TRANSACTION && $status != self::RESULT_UNKOWN) {
            $errorText = $errorText .
                        " ReCo:" .
                        $transactionResult["responseCode"] .
                        " ResponseText:" .
                        $transactionResult["responseText"];
        } else {
            $errorText = $errorText . " transaction not found";
        }
    
        if ($status == self::APPROVED) {
            $success = true;
            $errorText = "";
        } else {
            $this->_logger->critical(__METHOD__." status:{$status} ". $errorText);
        }
        
        $response = [
            "Success" => $success,
            "Error" => $errorText
        ];
        
        // set http://stackoverflow.com/questions/2483771/how-can-i-convince-ie-to-simply-display-application-json-rather-than-offer-to-dow
        // Use 'text/plain' to avoid IE display download
        $this->getResponse()->setHeader('Content-type', 'text/plain');
        $jsonContent = \Zend_Json::encode($response, false, []);
        $this->getResponse()->setContent($jsonContent);
        
        $this->_logger->info(__METHOD__ . " jsonContent:{$jsonContent} response:" . var_export($response, true));
    }
}
