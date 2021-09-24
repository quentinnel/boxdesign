<?php
namespace Windcave\Payments\Model\Config\Source;

class ApplePayButtonColorOptions
{
    const WHITE = 'white';
    const BLACK = 'black';
    const WHITE_WLINE = 'white-outline';
    
    public function toOptionArray()
    {
        return [
            [
                'value' => self::WHITE,
                'label' => 'White'
            ],
            [
                'value' => self::BLACK,
                'label' => 'Black'
            ],
            [
                'value' => self::WHITE_WLINE,
                'label' => 'White with Line'
            ]
        ];
    }
}
