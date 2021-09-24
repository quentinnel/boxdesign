/*browser:true*/
/*global define*/
define(
    [
        'jquery',
        'ko',
        'Magento_Checkout/js/model/quote',
        'Windcave_Payments/js/adapter',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_Checkout/js/action/create-billing-address',
        'mage/url',
    ],
    function ($, ko, quote, applePay, Component, additionalValidators, createBillingAddress, urlBuilder) {
    var applePayConfig = window.checkoutConfig.payment.windcave.applepay; //corresponds in configprovider return
    
    var applePayUiController = (function () {
        var DOMStrings = {
            appleButton: 'ckoApplePay',
            errorMessage: 'ckoApplePayError'
        }
        return {
            DOMStrings,
            displayApplePayButton: function () {
                $("#" + DOMStrings.appleButton).show();
            },
            hideApplePayButton: function () {
                $("#" + DOMStrings.appleButton).hide();
            },
            displayErrorMessage: function () {
                $("#" + DOMStrings.errorMessage).show();
            },
            hideErrorMessage: function () {
                $("#" + DOMStrings.errorMessage).hide();
            }
        }
    })()

    return Component.extend({
        defaults: {
            template: 'Windcave_Payments/payment/apple-payment',
            active: false,
            grandTotalAmount: null,
            imports: {
                onActiveChange: 'active'
              }
        },
        redirectAfterPlaceOrder: false,
        /**
         * Set list of observable attributes
         * @returns {exports}
         */
        initObservable: function() {
            var self = this._super();
            console.info("initObservable");
            this._super().observe(['active']);
            
            this.grandTotalAmount = quote.totals()['base_grand_total'];
            quote.totals.subscribe(function() {
                if (self.grandTotalAmount !== quote.totals()['base_grand_total']) {
                self.grandTotalAmount = quote.totals()['base_grand_total'];
                }
            });
            self.initApplePay();
            return self;
        },
        getButtonTitle: function() {
            return applePayConfig.buttonType;
        },
        getButtonColor: function() {
            return applePayConfig.buttonColor;
        },
        getSupportedNetworks: function() {
            var networks = applePayConfig.supportedNetworks;
            //console.info("Supported Networks: " + networks)
            return networks.split(',');
        },
        /**
         * Disable submit button
        */
         disableButton: function() {
            // stop any previous shown loaders
            applePayUiController.hideApplePayButton();
            applePayUiController.displayErrorMessage();
        },
        /**
         * Enable submit button
         */
        enableButton: function() {
            applePayUiController.displayApplePayButton();
            applePayUiController.hideErrorMessage();
        },
        initApplePay: function() {
            applePay.configure({
                domainName: location.hostname,
                method: applePayConfig.method,
                onOrderAuthorized: function(callback) {
                    console.log("Place order");
                    callback(this.placeOrder());
                }.bind(this)
            });
        },
        //Event to process the payment - pass to apple
        /**
         * @override
         */
        payWithApplePay: function() {
            console.log("payWithApplePay");   
            if (additionalValidators.validate()) {      
                var totals = quote.totals();
                applePay.process({
                    total: {
                        label: applePayConfig.merchantName,
                        amount: this.grandTotalAmount
                    },
                    currencyCode: totals['base_currency_code'],
                    shippingContact: this.getShippingContact(),
                    supportedNetworks: this.getSupportedNetworks()
                });
            }
        },
        /**
         * Triggers when payment method change
         * @param {Boolean} isActive
        */
        onActiveChange: function (active) {
            if (active === false) {
                this.disableButton();
                return;
            }
            this.initApplePay();
        }, 
        /**
         * Check if payment is active
         * @returns {Boolean}
         */
        isActive: function() {
            applePay.canMakePaymentsWithActiveCard(applePayConfig.merchantIdentifier, function(canMakePayment, msg) {
                console.info("isActive: " + canMakePayment + " - " + msg);
                this.active = canMakePayment;
                if(!canMakePayment) {
                    console.info("hide button");
                    $("#apple-pay-payment-method").css("display","none");
                    applePayUiController.hideApplePayButton();
                    applePayUiController.displayErrorMessage();
                } else {
                    console.info("display button");
                    $("#apple-pay-payment-method").css("display","block");
                    applePayUiController.displayApplePayButton();
                    applePayUiController.hideErrorMessage();
                }
            });
            
            return this.active;
        },
        /**
         * Get shipping address
         * @returns {Object}
         */
        getShippingContact: function() {
            var address = quote.shippingAddress();
    
            if (address.postcode === null) {
                return {};
            }
    
            return {
                givenName: address.firstname,
                familyName: address.lastname,
                addressLines: address.street,
                countryCode: address.countryId,
                locality: address.city,
                administrativeArea: address.region,
                postalCode: address.postcode,
                phoneNumber: address.telephone
            };
        },
        /**
         * Update quote billing address
         * @param {Object}customer
         * @param {Object}address
         */
        setBillingAddress: function(customer, address) {
            var billingAddress = {
                street: address.addressLines,
                city: address.locality,
                postcode: address.postalCode,
                countryId: address.countryCode,
                email: customer.emailAddress,
                firstname: customer.givenName,
                lastname: customer.familyName,
                telephone: customer.phoneNumber
            };

            billingAddress['region'] = address.administrativeArea;
            billingAddress = createBillingAddress(billingAddress);
            quote.billingAddress(billingAddress);
        },

        afterPlaceOrder: function() {
            console.log("Magento trigger > afterPlaceOrder");
            applePay.performPayment(  
                function(err, payload) {
                    if (err)
                    {
                        console.log('Payment complete with error: ' + err);
                        applePay.applesession.completePayment(ApplePaySession.STATUS_FAILURE);
                        window.location.href = urlBuilder.build("checkout/cart");
                    } else {
                        applePay.applesession.completePayment(ApplePaySession.STATUS_SUCCESS);
                        window.location.href = urlBuilder.build("checkout/onepage/success");
                    }
                }
            )
        }
    });
});