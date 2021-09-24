<?php
namespace Windcave\Payments\Block\Adminhtml;

class MultiSelectCardNetworkField extends \Magento\Config\Block\System\Config\Form\Field
{
    protected $_template = 'Windcave_Payments::card_network_comp_tpl.phtml';
    const TEMPLATE = 'Windcave_Payments::card_network_comp_tpl.phtml';

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $_objectManager;

    /**
     *
     * @var \Windcave\Payments\Logger\DpsLogger
     */
    protected $_logger;

    /**
     *
     * @param \Magento\Backend\Block\Template\Context $context
     * @param array $data
     */
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->_objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_logger = $this->_objectManager->get(\Windcave\Payments\Logger\DpsLogger::class);
    }

    /**
     *
     * @return $this
     */
    protected function _prepareLayout()
    {
        $this->_logger->info(__METHOD__);
        parent::_prepareLayout();
        if (!$this->getTemplate()) {
            $this->setTemplate(static::TEMPLATE);
        }
        return $this;
    }

    /**
     * Retrieve element HTML markup.
     *
     * @param \Magento\Framework\Data\Form\Element\AbstractElement $element
     *
     * @return string
     */
    protected function _getElementHtml(\Magento\Framework\Data\Form\Element\AbstractElement $element)
    {
        $this->_logger->info(__METHOD__ . " name: " . $element->getName() . " id: " . $element->getHtmlId());
        $this->setElement($element);
        $this->setNamePrefix($element->getName())
            ->setHtmlId($element->getHtmlId());
        return $this->_toHtml();
    }

    public function getNetworkOptions()
    {
        $this->_logger->info(__METHOD__);
        $networkOptions = $this->_objectManager->create(
            Windcave\Payments\Model\Config\Source\NetworkOptions::class
        )->toOptionArray();
        return json_encode($networkOptions);
    }

    public function getSelectedNetworkOptions()
    {
        $this->_logger->info(__METHOD__);
        $selectedNetworkOptions = $this->getElement()->getData("value");
        return json_encode($selectedNetworkOptions);
    }

    public function getName()
    {
        $this->_logger->info(__METHOD__);
        return $this->getNamePrefix();
    }
}
