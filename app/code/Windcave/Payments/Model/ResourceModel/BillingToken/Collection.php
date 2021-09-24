<?php
namespace Windcave\Payments\Model\ResourceModel\BillingToken;

use \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{

    protected function _construct()
    {
        $this->_init('Windcave\Payments\Model\BillingToken', 'Windcave\Payments\Model\ResourceModel\BillingToken');
    }
}
