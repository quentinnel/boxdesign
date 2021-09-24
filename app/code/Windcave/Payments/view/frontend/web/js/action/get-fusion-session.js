define([ 'jquery',
        'Magento_Checkout/js/model/url-builder',
        'mage/storage',
        'Magento_Checkout/js/model/error-processor',
        'Magento_Customer/js/model/customer',
        'Magento_Checkout/js/model/full-screen-loader',
        'Magento_Customer/js/customer-data',
        'Magento_Ui/js/modal/modal'], 
        function($, urlBuilder, storage, errorProcessor, customer, fullScreenLoader, customerData, modal){
    
    'use strict';

    return function(messageContainer, module, onSuccess){
        customerData.invalidate(['cart']);

        var serviceUrl;
        if (!customer.isLoggedIn()) {
            serviceUrl = urlBuilder.createUrl('/guest-carts/:module/get-fusion-session', { module : module });
        }
        else {
            serviceUrl = urlBuilder.createUrl('/carts/mine/:module/get-fusion-session', { module : module });
        }

        fullScreenLoader.startLoader();
        
        return storage.get(serviceUrl)
            .done(function(sessionData){
                if (onSuccess) {
                    if (onSuccess(sessionData))
                        fullScreenLoader.stopLoader();
                }
                else
                    fullScreenLoader.stopLoader();
                // $.mage.redirect(redirectUrl);
            })
            .fail(function(response){
                fullScreenLoader.stopLoader();
                try {
                    errorProcessor.process(response, messageContainer);
                }
                catch (e) {
                    var errorResponse = { status: 500, responseText: JSON.stringify({ message: "Internal server error" }) };
                    errorProcessor.process(errorResponse, messageContainer);

                    var options = {
                        type: 'popup',
                        responsive: true,
                        innerScroll: true,
                        title: 'Internal server error.',
                        buttons: [{
                            text: $.mage.__('Continue'),
                            class: '',
                            click: function () {
                                this.closeModal();
                            }
                        }]
                    };
                    $('<div></div>').html('Please contact support.').modal(options).modal('openModal');
                }
            });
    };
});
