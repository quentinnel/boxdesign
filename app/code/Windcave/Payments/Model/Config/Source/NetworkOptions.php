<?php
namespace Windcave\Payments\Model\Config\Source;

class NetworkOptions implements \Magento\Framework\Option\ArrayInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'Visa', 'label' => __('Visa')],
            ['value' => 'Mastercard', 'label' => __('Mastercard')],
            ['value' => 'Amex', 'label' => __('Amex')],
            ['value' => 'Diners', 'label' => __('Diners')],
            ['value' => 'Discover', 'label' => __('Discover')],
            ['value' => 'JCB', 'label' => __('JCB')]
        ];
    }
}
