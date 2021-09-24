<?php
namespace Windcave\Payments\Model\ResourceModel;

use \Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class BillingToken extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('windcave_billingtoken', 'entity_id');
    }
}
