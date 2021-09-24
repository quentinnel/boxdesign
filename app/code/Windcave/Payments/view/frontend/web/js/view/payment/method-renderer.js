define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'windcave_pxfusion',
                component: 'Windcave_Payments/js/view/payment/method-renderer/dps-pxfusion'
            }
        );
        rendererList.push(
            {
                type: 'windcave_pxpay2',
                component: 'Windcave_Payments/js/view/payment/method-renderer/dps-payment'
            }
        );

        rendererList.push(
            {
                type: 'windcave_pxpay2_iframe',
                component: 'Windcave_Payments/js/view/payment/method-renderer/pxpay2-iframe'
            }
        );

        rendererList.push(
            {
                type: 'windcave_applepay',
                component: 'Windcave_Payments/js/view/payment/method-renderer/apple-payment'
            }
        );

        return Component.extend({});
    }
);
