<?php
namespace Windcave\Payments\Model\Config\Source;

class PaymentOptions
{

    const PURCHASE = 'Purchase';
    const AUTH = 'Auth';
    
    public function toOptionArray()
    {
        return [
            [
                'value' => self::PURCHASE,
                'label' => 'Purchase'
            ],
            [
                'value' => self::AUTH,
                'label' => 'Authorise Only'
            ]
        ];
    }
}
