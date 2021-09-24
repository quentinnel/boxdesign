<?php
namespace Windcave\Payments\Model\ResourceModel;

use \Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class PaymentResult extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('windcave_paymentresult', 'entity_id');
    }
}
