define([ 'jquery',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/model/url-builder',
        'mage/storage',
        'Magento_Checkout/js/model/error-processor',
        'Magento_Customer/js/model/customer',
        'Magento_Checkout/js/model/full-screen-loader',
        'Magento_Customer/js/customer-data',
        'Magento_Ui/js/modal/modal',
        'mage/url'], 
        function($, quote, urlBuilder, storage, errorProcessor, customer, fullScreenLoader, customerData, modal, mageUrl){
    
    'use strict';

    var overlay;
    var lockedElements = [];

    var lockInput = function() {
        var inputs = document.getElementsByTagName("input"); 
        for (var i = 0; i < inputs.length; i++) { 
            if (!inputs[i].disabled) {
                lockedElements.push(inputs[i]);
                inputs[i].disabled = true;
            }
        } 
        var selects = document.getElementsByTagName("select");
        for (var i = 0; i < selects.length; i++) {
            if (!selects[i].disabled) {
                lockedElements.push(selects[i]);
                selects[i].disabled = true;
            }
        }
        var textareas = document.getElementsByTagName("textarea"); 
        for (var i = 0; i < textareas.length; i++) { 
            if (!textareas[i].disabled) {
                lockedElements.push(textareas[i]);
                textareas[i].disabled = true;
            }
        }
        var buttons = document.getElementsByTagName("button");
        for (var i = 0; i < buttons.length; i++) {
            if (!buttons[i].disabled) {
                lockedElements.push(buttons[i]);
                buttons[i].disabled = true;
            }
        }
    };

    var unlockInput = function() {
        if (!lockedElements) return;
        lockedElements.forEach(function(elem) {
            elem.disabled = false;
        });
    };

    var showOverlay = function() {
        if (!overlay) {
            overlay = $('<div id="windcave_pxpay2_iframe_overlay" style="position: absolute; top:0px; left:0px; right:0px; bottom:0px; background-color:rgba(255, 255, 255, 0.2); display: none; z-index:9990"></div>');
            $(document.body).append(overlay);
        }
        $(overlay).css("display", "block");
    };

    var hideOverlay = function() {
        if (!overlay) return;
        $(overlay).css("display", "none");
    };

    var constructIframe = function(containerId, url, module, iframeWidth, iframeHeight) {
        $("#windcave_pxpay_iframe_content").css("display", "none");

        var containerIdEx = "#" + containerId;
        $(containerIdEx).empty();

        showOverlay();
        lockInput();

        try {
            var iframe = $('<iframe>', {
                src: url,
                class: 'windcave_pxpay2_iframe',
                id: containerId + '_iframe',
                frameborder: 0,
                scrolling: 'no',
                width: iframeWidth + 'px',
                height: iframeHeight + 'px'
            });
            iframe.css("z-index", "9999");
            iframe.css("position", "relative");
            iframe.appendTo(containerIdEx);
            iframe.on("load", function() {
                var content = iframe[0].contentWindow;
                if (content) {
                    try {
                        if (content.location && content.location.host) {
                            hideOverlay();
                            $(containerIdEx).css("display", "none");
                            fullScreenLoader.startLoader();

                            var query = content.location.search;
                            var returnUrl = mageUrl.build(module + '/pxpay2iframe/redirect/' + query);

                            // Adding a 0ms delay to let browser redraw the page and make full-screen loader appear
                            setTimeout(function() {
                                $.mage.redirect(returnUrl);
                            }, 0);
                        }
                    } catch(e)
                    { }
                } else {
                    $(containerIdEx).css("display", "block");
                }
            });
            
        }
        catch(e) {
            console.log("Constructing iFrame failed.");
            unlockInput();
        }
    };

    return function(messageContainer, module, iframeWidth, iframeHeight){
        customerData.invalidate(['cart']);

        var serviceUrl;
        if (!customer.isLoggedIn()) {
            serviceUrl = urlBuilder.createUrl('/guest-carts/:module/redirect-to-pxpay2', { module : module });
        }
        else {
            serviceUrl = urlBuilder.createUrl('/carts/mine/:module/redirect-to-pxpay2', { module : module });
        }

        fullScreenLoader.startLoader();
        
        return storage.get(serviceUrl)
            .done(function(redirectUrl){
                fullScreenLoader.stopLoader();
                constructIframe('windcave_pxpay_iframe_container', redirectUrl, module, iframeWidth, iframeHeight);
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
