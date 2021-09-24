<?php
namespace Windcave\Payments\Model\ResourceModel\PaymentResult;

use \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init('Windcave\Payments\Model\PaymentResult', 'Windcave\Payments\Model\ResourceModel\PaymentResult');
    }
}
