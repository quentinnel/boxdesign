<?php
namespace Windcave\Payments\Model\Config\Source;

class ApplePayButtonOptions
{

    const BUY = 'buy';
    const DONATE = 'donate'; /* only for approved nonprofit otherwise good as plain */
    const APPLEPAY = 'plain';
    const BOOK = 'book';
    const ORDER  = 'order';
    
    public function toOptionArray()
    {
        return [
            [
                'value' => self::BUY,
                'label' => 'Buy with Apple Pay'
            ],
            [
                'value' => self::DONATE,
                'label' => 'Donate with Apple Pay'
            ],
            [
                'value' => self::BOOK,
                'label' => 'Book with Apple Pay'
            ],
            [
                'value' => self::ORDER,
                'label' => 'Order with Apple Pay'
            ],
            [
                'value' => self::APPLEPAY,
                'label' => 'Apple Pay'
            ]
        ];
    }
}
