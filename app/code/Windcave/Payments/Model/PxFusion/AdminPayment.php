<?php
namespace Windcave\Payments\Model\PxFusion;

class AdminPayment extends Payment
{
    const CODE = "windcave_pxfusion_admin";

    protected $_code = "windcave_pxfusion_admin";

    protected $_formBlockType = 'Windcave\Payments\Block\PxFusion\Adminhtml\Form';

    protected $_canUseInternal = true;

    protected $_canUseCheckout = false;

    protected $_isInitializeNeeded = false;
}
