<?php

namespace Magento360\Base\Block;

use Magento\Framework\View\Element\Template;
use Magento\Store\Model\StoreManagerInterface;

class WebsiteSwitcher extends Template
{

    /**
     * @var StoreManagerInterface
     */
    protected $_storeManager;

    public function __construct(
        Template\Context $context,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        $this->_storeManager = $storeManager;
        parent::__construct($context, $data);
    }

    /**
     * @return \Magento\Store\Api\Data\WebsiteInterface[]
     */
    public function getWebsites()
    {
        return $this->_storeManager->getWebsites();
    }

    /**
     * @return int
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getCurrentWebsiteId()
    {
        return $this->_storeManager->getWebsite()->getId();
    }

    /**
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getCurrentWebsiteCode()
    {
        return $this->_storeManager->getWebsite()->getCode();
    }

    /**
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getCurrentWebsiteName()
    {
        return $this->_storeManager->getWebsite()->getName();
    }
}
