<?php
namespace Windcave\Payments\Model\Rewrite\Order\Pdf;
 
use Magento\Sales\Model\ResourceModel\Order\Invoice\Collection;
 
class Invoice extends \Magento\Sales\Model\Order\Pdf\Invoice
{
    private $_logger;
    private $_objectManager;
    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;
    /**
     * @var \Magento\Store\Model\App\Emulation
     */
    private $appEmulation;
    
    /**
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param \Magento\Framework\Stdlib\StringUtils $string
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\Filesystem $filesystem
     * @param \Magento\Sales\Model\Order\Pdf\Config $pdfConfig
     * @param \Magento\Sales\Model\Order\Pdf\Total\Factory $pdfTotalFactory
     * @param \Magento\Sales\Model\Order\Pdf\ItemsFactory $pdfItemsFactory
     * @param \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate
     * @param \Magento\Framework\Translate\Inline\StateInterface $inlineTranslation
     * @param \Magento\Sales\Model\Order\Address\Renderer $addressRenderer
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Store\Model\App\Emulation $appEmulation
     * @param array $data
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    
    public function __construct(
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\Stdlib\StringUtils $string,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Sales\Model\Order\Pdf\Config $pdfConfig,
        \Magento\Sales\Model\Order\Pdf\Total\Factory $pdfTotalFactory,
        \Magento\Sales\Model\Order\Pdf\ItemsFactory $pdfItemsFactory,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate,
        \Magento\Framework\Translate\Inline\StateInterface $inlineTranslation,
        \Magento\Sales\Model\Order\Address\Renderer $addressRenderer,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Store\Model\App\Emulation $appEmulation,
        array $data = []
    ) {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_logger = $objectManager->get("\Windcave\Payments\Logger\DpsLogger");
        $this->_logger->info(__METHOD__);
        $this->_storeManager = $storeManager;
        $this->appEmulation = $appEmulation;
        parent::__construct(
            $paymentData,
            $string,
            $scopeConfig,
            $filesystem,
            $pdfConfig,
            $pdfTotalFactory,
            $pdfItemsFactory,
            $localeDate,
            $inlineTranslation,
            $addressRenderer,
            $storeManager,
            $appEmulation,
            $data
        );
    }
    /**
     * Return PDF document
     *
     * @param array|Collection $invoices
     * @return \Zend_Pdf
     */
    public function getPdf($invoices = [])
    {
        $this->_logger->info("Override for manipulation before turning over to parent " . __METHOD__);
        //manipulate invoices
        $this->_beforeGetPdf();
        $this->_initRenderer('invoice');

        $pdf = new \Zend_Pdf();
        $this->_setPdf($pdf);
        $style = new \Zend_Pdf_Style();
        $this->_setFontBold($style, 10);

        $payment_fields_allowed  = "dpstxnref, cardname, cardholdername";
        $fields_allowed = explode(",", $payment_fields_allowed);

        foreach ($invoices as $invoice) {
            if ($invoice->getStoreId()) {
                $this->appEmulation->startEnvironmentEmulation(
                    $invoice->getStoreId(),
                    \Magento\Framework\App\Area::AREA_FRONTEND,
                    true
                );
                $this->_storeManager->setCurrentStore($invoice->getStoreId());
            }
            $page = $this->newPage();
            $order = $invoice->getOrder();
            /* Add image */
            $this->insertLogo($page, $invoice->getStore());
            /* Add address */
            $this->insertAddress($page, $invoice->getStore());
            /* Add head */
            if ($order instanceof \Magento\Sales\Model\Order) {
                $this->_logger->info(__METHOD__ . " Sales Order Type");
                $orderPayment = $order->getPayment();
                //manipulate here
                $paymentInfo = $this->_paymentData->getInfoBlock($orderPayment)->setIsSecureMode(true)->toPdf();
                $paymentInfo = htmlspecialchars_decode($paymentInfo, ENT_QUOTES);
                $payment = explode('{{pdf_row_separator}}', $paymentInfo);
                $this->_logger->info(__METHOD__ . " Payment Block = " . var_export($payment, true));

                $x = 0;
                $new_payment = [];
                foreach ($payment as $value) {
                    $x++;
                    if (trim($value) != '') {
                        $value = preg_replace('/<br[^>]*>/i', "\n", $value);
                        $this->_logger->info(__METHOD__ . " 01" . $value);
                        foreach ($this->string->split($value, 45, true, true) as $_value) {
                            $this->_logger->info(__METHOD__ . " 02" . $_value);
                            $line = str_getcsv(trim($_value), ":");
                            if (preg_match('/\b'.$line[0].'\b/i', implode(', ', $fields_allowed), $matches)) {
                                switch (strtolower($line[0])) {
                                    case "dpstxnref":
                                        $line[0] = "Transaction Reference: ";
                                        break;
                                    case "cardname":
                                        $line[0] = "Card Type: ";
                                        break;
                                    case "cardholdername":
                                        $line[0] = "Cardholder Name: ";
                                        break;
                                }
                                $new_payment = array_merge($new_payment, [$line[0] => $line[1]]);
                            }
                        }
                    }
                }//assign the new payment back to the order
                $this->_logger->info(__METHOD__ . " 04" . var_export($new_payment, true));
                $orderPayment->setAdditionalInformation($new_payment);
                $this->_logger->info(__METHOD__ . " 05");
            } else {
                $this->_logger->info(__METHOD__ . " Shipment Type");
            }
            $this->insertOrder(
                $page,
                $order,
                $this->_scopeConfig->isSetFlag(
                    self::XML_PATH_SALES_PDF_INVOICE_PUT_ORDER_ID,
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                    $order->getStoreId()
                )
            );
            /* Add document text and number */
            $this->insertDocumentNumber($page, __('Invoice # ') . $invoice->getIncrementId());
            /* Add table */
            $this->_drawHeader($page);
            /* Add body */
            foreach ($invoice->getAllItems() as $item) {
                if ($item->getOrderItem()->getParentItem()) {
                    continue;
                }
                /* Draw item */
                $this->_drawItem($item, $page, $order);
                $page = end($pdf->pages);
            }
            /* Add totals */
            $this->insertTotals($page, $invoice);
            if ($invoice->getStoreId()) {
                $this->appEmulation->stopEnvironmentEmulation();
            }
        }
        $this->_afterGetPdf();
        return $pdf;
    }
    /**
     * Set font as regular
     *
     * @param \Zend_Pdf_Page $object
     * @param int $size
     * @return \Zend_Pdf_Resource_Font
     */
    protected function _setFontRegular($object, $size = 7)
    {
        $this->_logger->info(__METHOD__);
        $font = \Zend_Pdf_Font::fontWithPath(
            $this->_rootDirectory->getAbsolutePath('lib/internal/LinLibertineFont/LinLibertine_Re-4.4.1.ttf')
        );
        $object->setFont($font, $size);
        return $font;
    }
 
    /**
     * Set font as bold
     *
     * @param \Zend_Pdf_Page $object
     * @param int $size
     * @return \Zend_Pdf_Resource_Font
     */
    protected function _setFontBold($object, $size = 7)
    {
        $this->_logger->info(__METHOD__);
        $font = \Zend_Pdf_Font::fontWithPath(
            $this->_rootDirectory->getAbsolutePath('lib/internal/LinLibertineFont/LinLibertine_Bd-2.8.1.ttf')
        );
        $object->setFont($font, $size);
        return $font;
    }
 
    /**
     * Set font as italic
     *
     * @param \Zend_Pdf_Page $object
     * @param int $size
     * @return \Zend_Pdf_Resource_Font
     */
    protected function _setFontItalic($object, $size = 7)
    {
        $this->_logger->info(__METHOD__);
        $font = \Zend_Pdf_Font::fontWithPath(
            $this->_rootDirectory->getAbsolutePath('lib/internal/LinLibertineFont/LinLibertine_It-2.8.2.ttf')
        );
        $object->setFont($font, $size);
        return $font;
    }
}
