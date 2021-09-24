<?php
namespace Windcave\Payments\Controller\Adminhtml\PxFusion;

class CreateTransaction extends \Magento\Framework\App\Action\Action
{

    /**
     *
     * @var \Magento\Framework\Controller\Result\JsonFactory
     */
    protected $_resultJsonFactory;

    /**
     *
     * @var \Windcave\Payments\Logger\DpsLogger
     */
    private $_logger;

    /**
     *
     * @var \Magento\Backend\Model\Session\Quote
     */
    private $_quoteSession;
    
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
     * @var \Windcave\Payments\Helper\PxFusion\Communication
     */
    private $_communication;

    public function __construct(
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory,
        \Magento\Framework\App\Action\Context $context
    ) {
        parent::__construct($context);

        $this->_resultJsonFactory = $resultJsonFactory;
        $this->_quoteSession = $this->_objectManager->get(\Magento\Backend\Model\Session\Quote::class);
        $this->_quoteRepository = $this->_objectManager->get(\Magento\Quote\Model\QuoteRepository::class);
        $this->_configuration = $this->_objectManager->get(\Windcave\Payments\Helper\PxFusion\Configuration::class);
        $this->_communication = $this->_objectManager->get(\Windcave\Payments\Helper\PxFusion\Communication::class);
        $this->_logger = $this->_objectManager->get(\Windcave\Payments\Logger\DpsLogger::class);
        $this->_logger->info(__METHOD__);
    }

    public function execute()
    {
        // return json:
        // http://magento.stackexchange.com/questions/99358/magento2-how-to-get-json-response-from-controller
        $this->_logger->info(__METHOD__);
        
        $quote = $this->_quoteSession->getQuote();
        $quote->reserveOrderId();
        $this->_quoteRepository->save($quote);
        $transactionId = $this->_communication->createTransaction($quote, $this->_buildReturnUrl());
        $postUrl = $this->_configuration->getPostUrl($quote->getStoreId());
        
        $response = [
            "Success" => !empty($transactionId),
            "TransactionId" => $transactionId,
            "PostUrl" => $postUrl
        ];
        
        $result = $this->_resultJsonFactory->create();
        $result = $result->setData($response);
        return $result;
    }
    
    private function _buildReturnUrl()
    {
        $url =  $this->_url->getBaseUrl()."/pxpay2/pxfusion/adminresult";
        $this->_logger->info(__METHOD__." url:{$url}");
        return $url;
    }
}
