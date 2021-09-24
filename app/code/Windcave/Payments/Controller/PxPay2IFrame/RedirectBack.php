<?php
namespace Windcave\Payments\Controller\PxPay2IFrame;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;

class RedirectBack implements HttpGetActionInterface
{
    /**
     *
     * @var \Windcave\Payments\Logger\DpsLogger
     */
    private $_logger;
    /**
     * @var RequestInterface
     */
    private $_request;

    /**
     *
     * @var \Magento\Framework\UrlInterface
     */
    private $_urlBuilder;

    /**
     *
     * @var RawFactory;
     */
    private $_resultRawFactory;
    
    /**
     *
     * @var PageFactory;
     */
    private $_resultPageFactory;

    /**
     * @var \Magento\Framework\Stdlib\CookieManagerInterface
     */
    protected $_cookieManager;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        RawFactory $resultRawFactory,
        PageFactory $resultPageFactory,
        RequestInterface $request,
        CookieManagerInterface $cookieManager
    ) {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_logger = $objectManager->get("\Windcave\Payments\Logger\DpsLogger");
        $this->_logger->info(__METHOD__);

        $this->_resultRawFactory = $resultRawFactory;
        $this->_resultPageFactory = $resultPageFactory;
        $this->_request = $request;
        $this->_cookieManager = $cookieManager;
        $this->_urlBuilder = $context->getUrl();
    }

    public function execute()
    {
        $formKey2 = $this->getCookie('form_key');
        $this->_logger->info(__METHOD__ . " >>> " . $formKey2);

        if (isset($formKey2)) {
            //has formkey in request
            $pxPayUserId = $this->_request->getParam('userid', null);
            $token = $this->_request->getParam('result', null);
            $url = $this->_urlBuilder->getUrl("pxpay2/pxpay2iframe/redirect/", [
                "_secure" => true,
                "_query" => [
                    "userid" => $pxPayUserId,
                    "result" => $token
                ]
            ]);
            $resultRaw = $this->_resultRawFactory->create();
            $resultRaw->setHeader('Content-type', "text/html; charset=UTF-8")->setContents("
                <!doctype html>
                <html><body>
                    <script>
                    try {
                        if (!window.frameElement) {
                            window.location = '${url}';
                        }
                    }
                    catch (e) {
                    }
                    </script>
                </body></html>");
            return $resultRaw;
        } else {
            //has no formkey
            return $this->_resultPageFactory->create();
        }
    }
    /**
     * Get data from cookie set in remote address
     *
     * @return value
     */
    public function getCookie($name)
    {
        return $this->_cookieManager->getCookie($name);
    }
}
