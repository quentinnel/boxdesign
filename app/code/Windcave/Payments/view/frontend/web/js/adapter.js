define([
    'jquery',
    'ko',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/url-builder',
    'mage/storage',
    'Magento_Customer/js/model/customer',
    'Magento_Checkout/js/model/full-screen-loader',
    'Magento_Checkout/js/model/error-processor',
    'Magento_Ui/js/modal/modal'
  ], function($, ko, quote, urlBuilder, storage, customer, fullScreenLoader, errorProcessor, modal) {
    'use strict';

    /**
     * @returns {boolean}
     */
    function isFunction(fn) {
        return typeof fn === 'function';
    }
    function calculateTotalFromLineItems(lineItems) {
		var total, x, item;

		// Initialize the new total as a float.
		total = 0.0;

		for (x in lineItems) {
			item = lineItems[x];
			total += parseFloat(item.amount);
		}
        // Returns the total as a string with two decimal places.
		return "" + total.toFixed(2);
	}

    var base = {
        applesession: null,
        appledata: null,
        quote: null,
        customer: null,

        domain: null,
        config: {
            clientToken: null,
            payment: {
                merchantCapabilities: ['supports3DS'],
                countryCode: 'NZ',
                requiredBillingContactFields: ['postalAddress', 'email', 'phone', 'name'],
                requiredShippingContactFields:['postalAddress', 'name'],
                currencyCode: 'NZD',
                shippingType: "shipping" //"delivery" , "storePickup" , "servicePickup"
            }
        }
    };
    base.configure = function(config) {
        this.config = _.extend(this.config, config);
    };
    base.init = function() {
        console.info("adapter init");
    };
    base.canMakePayments = function() {
        if (!window.ApplePaySession) {
            console.info('This device does not support Apple Pay');
            return false;
        }
        if (!ApplePaySession.canMakePayments()) {
            console.info('This device is not capable of making Apple Pay payments');
            return false;
        }
        return true;
    };
    base.canMakePaymentsWithActiveCard = function(merchantId, callback) {
        if (window.ApplePaySession) {
            var promise = ApplePaySession.canMakePaymentsWithActiveCard(merchantId);
            promise.then(function (canMakePayments) {
                if (canMakePayments) {
                    callback(true, "Capable of making ApplePay payments");
                } else {
                    callback(false, "Not capable of making ApplePay payments");
                }
            });
        }
        else {
            callback(false, "This device does not support Apple Pay");
        }
    };

    base.process = function(data) {
        var paymentRequest = _.extend(base.config.payment, data);
        base.applesession = new ApplePaySession(3, paymentRequest);
        //validate
        base.applesession.onvalidatemerchant = function (event) {
            console.info('>> onvalidatemerchant');
            //lets performValidation at the backend 
            base.performValidation({
                    validationURL: event.validationURL
                }, 
                function (err, httpCode, validationData) 
                {
                    if (err || !validationData) 
                    {
                        if(base.applesession) {
                            console.info("calling abort session");
                            //do something here or redirect to display error msg. or something
                            var options = {
                                type: 'popup',
                                responsive: true,
                                innerScroll: true,
                                title: 'Merchant validation error.',
                                buttons: [{
                                    text: $.mage.__('Continue'),
                                    class: '',
                                    click: function () {
                                        this.closeModal();
                                        base.applesession.abort(); //check this abort if available
                                    }
                                }]
                            };
                            $('<div></div>').html('The ApplePay merchant validation failed. Please use other payment method that Windcave is supporting. Please report this problem to the support team.').modal(options).modal('openModal');
                        }
                        return;
                    }
                    var jsObject= JSON.parse(JSON.stringify(validationData))
                    //console.info("sessionObject: " + JSON.stringify(jsObject.sessionObject));
                    base.applesession.completeMerchantValidation(JSON.parse(JSON.stringify(jsObject.sessionObject)));
                }
            )
        };
        //authorized
        base.applesession.onpaymentauthorized = function (event) {
            console.info('>> onpaymentauthorized');
            //This process is Quote > Payment > Order
            if (isFunction(base.config.onOrderAuthorized)) {
                //The process is Quote > Order > Payment
                base.config.onOrderAuthorized(function(bResult) {
                    if (bResult) {
                        //DUMMY DATA
                        //console.info("Load Mock ApplePay Payment");
                        //var apStr = JSON.stringify(base.setDummyData());
                        //var ap = JSON.parse(apStr);
                        //console.log("Apple Payment Token: " + apStr);
                        console.info("load ApplePay Payment data from apple");
                        base.appledata = event.payment;
                    } else {
                        console.info("onOrderAuthorized failed");
                        return;
                    }
                    //lets make the afterPlaceOrder to call the performPayment
                });
            }
        };
        //eventhandler for changes on payment method
        base.applesession.onpaymentmethodselected = function(event) {
            var update =  {
                "newTotal": {
                  "label": "Total", 
                  "amount": paymentRequest.total.amount //required as https://developer.apple.com/documentation/apple_pay_on_the_web/applepaypaymentmethodupdate/2928620-newtotal
                },
                "newLineItems": [] //only when new or updated cost or discounts
            };
            base.applesession.completePaymentMethodSelection(update);
        };
        base.applesession.begin();
    };
    base.performValidation = function (options, callback) {
        console.info("performValidation begin");
        var self = this;
        if (!options || !options.validationURL) {
            console.log("APPLE-PAY_VALIDATION_URL_REQUIRED");
            return;
        }
        var payload = {
            validationUrl: options.validationURL,
            domainName: options.domainName || location.hostname
        };
    
        var serviceUrl;
        if (!customer.isLoggedIn()) {
            serviceUrl = urlBuilder.createUrl('/guest-carts/:module/perform-validation', { module : "applepay" });
        } else {
            serviceUrl = urlBuilder.createUrl('/carts/mine/:module/perform-validation', { module : "applepay" });
        }
        fullScreenLoader.startLoader();
        console.info("validation URL: " + payload.validationUrl + " domain: " + payload.domainName);

        return storage.post(serviceUrl, JSON.stringify(payload)).done(
            function (response) {
                var jsonResponse = JSON.parse(response);
                console.info("base.performValidation >> done validation & has error = " + jsonResponse.error);
                /*var data = $.parseJSON(response);*/
                fullScreenLoader.stopLoader();
                callback(jsonResponse.error, jsonResponse.httpCode, jsonResponse.response);
                return jsonResponse;
            }).fail(
            function (response) {
                console.info("base.performValidation >> fail validation")
                fullScreenLoader.stopLoader();
                callback(true, 500, response);
            }
        );
    };
    base.performPayment = function (callback) {
        console.info("performPayment begin 2");
        if (!base.appledata) {
            console.info("PLACE ORDER FAILED");
            callback(true, "error");
            return;
        }
        var ap = base.appledata;
        
        //send the token to windcave
        var serviceUrl;
        var payload = {};
        var guestEmail;
        if(quote.guestEmail) {
            guestEmail=  quote.guestEmail;
        } else {
            guestEmail = window.checkoutConfig.customerData.email;
        }

        var additionalData = {};
        if (!customer.isLoggedIn()){
            additionalData["guestEmail"] = guestEmail;
        }
        //var tokenObj = JSON.parse(ap);
        additionalData["cartId"] = quote.getQuoteId();
        additionalData["paymentData"] = btoa(JSON.stringify(ap.token.paymentData)); //convert to base64 
        additionalData["transactionId"] = ap.token.transactionIdentifier;
        
        var paymentMethod = {
            'method': base.config.method,
            'additional_data' : additionalData
        };
        if (!customer.isLoggedIn()) {
            serviceUrl = urlBuilder.createUrl('/guest-carts/:module/perform-payment', { module : "applepay" });
            payload = {
                cartId : quote.getQuoteId(),
                email: guestEmail,
                token : btoa(JSON.stringify(ap.token)),
                method: paymentMethod,
                billingAddress: JSON.stringify(ap.billingContact)
            };
        } else {
            serviceUrl = urlBuilder.createUrl('/carts/mine/:module/perform-payment', { module : "applepay" });
            payload = {
                cartId : quote.getQuoteId(),
                token : btoa(JSON.stringify(ap.token)),
                method: paymentMethod,
                billingAddress: JSON.stringify(ap.billingContact)
            };
        }
        fullScreenLoader.startLoader();
        console.log("post " + serviceUrl);
        return storage.post(serviceUrl, JSON.stringify(payload)).done(
            function (response) {
                console.log('applepay.performPayment.succeeded');
                if (response == false) {
                    callback(true, response);
                }
                callback(false, response);
                return;
            }
        ).fail(
            function (response) {
                console.log('applepay.performPayment.failed');
                fullScreenLoader.stopLoader();
                callback(true, response);
            }
        );
    };
    //TO REMOVE IN PRODUCTION - JUST FOR TESTING - Normally this data is already populated by apple (based on ApplePayPaymentRequest we included at the start)
    //REMEMBER: The paymentData contains {amount=1.00m, currency=NZD} make sure for testing we have similar amount.
    base.setDummyData = function () {
        return {
        "token":{
            "paymentMethod":{
                "displayName":"displayName",
                "network":"network",
                "type":"debit,credit",
            },
            "transactionIdentifier":"1234",
            "paymentData": {
                "data": "zsdOHpZb0gSN4lYnWMiXcAB7WZ\/cbiUTY1XMYA4ZZ3Zn6OjqFXPNi9e6Ug8cy0fvwtZZD9AecyOG\/r3zwbCED1cvci1MrJcFe686ECIig5guBFJgQ69Aj\/nz89Ah6TocnfXxmjS4OGrzOYn3aDeEd6SlLn+fX69FANDaSshBbOXd4+1ReAkvY\/qYlmGJs\/gQdoFzft6KhhtRSzolxnsPZa4c8DubgnVIFM5fH8ojI67Gfu+4ebvrhbQLSBiYJDV2dKwfCr0IUJ\/WTpSKwxxKAc0AIibXpMS52fAXIi5WJ5UuE\/YRu3H6XMZyVhLJTaBb5LT\/6RJ3BlvHIvxeceWxVArz2yb801kSPivgU4vZ8MUeZ65IYd\/2fSZOPDX+cJEpGf5fclQLamBPDXejtZdnuueQDQXvAtHXkl2CpsjyyPI3\/JBVSaH428VrXvzIwC59AScN8I5gV1bDT\/tCFmIasoY9kaqr21UxVg\/zVaaYLTGOevG6L665sChvzyouTQ5EZ37QUJ4AZ6CDbRrCc4prChHQJLH2RQYmYkAFXt5Ocu22bN2cD+TA",
                "header": {
                    "ephemeralPublicKey": "MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEug18yWDpwZD2Ot9+n8LpUUvCd6Qd6gakd+NYdZAG4ihqKJqIuhpzyV90CowOzVJmoYmqX4ZY4A3Gk+LU5XHmAQ==",
                    "publicKeyHash": "RAGNaR1\/Jj0RL\/maZ6pL4tOy1rzkD0tidUwEMxyAkas=",
                    "transactionId": "3031323334353433323130"
                },
                "signature": "MIIIUAYJKoZIhvcNAQcCoIIIQTCCCD0CAQExDzANBglghkgBZQMEAgEFADALBgkqhkiG9w0BBwGgggXyMIIC7zCCApagAwIBAgIBATAKBggqhkjOPQQDAjCBxjEtMCsGCSqGSIb3DQEJARYeaWdvci5rcnVzY2hAcGF5bWVudGV4cHJlc3MuY29tMSgwJgYDVQQDDB9EUFMgRGV2ZWxvcG1lbnQgSW50ZXJtZXJpYXRlIENBMRQwEgYDVQQLDAtEZXZlbG9wbWVudDEhMB8GA1UECgwYRGlyZWN0IFBheW1lbnQgU29sdXRpb25zMRIwEAYDVQQHDAlFbGxlcnNsaWUxETAPBgNVBAgMCEF1Y2tsYW5kMQswCQYDVQQGEwJOWjAeFw0xNjA5MjcwMDEzNDBaFw0yNjA5MjUwMDEzNDBaMIG+MS0wKwYJKoZIhvcNAQkBFh5pZ29yLmtydXNjaEBwYXltZW50ZXhwcmVzcy5jb20xIDAeBgNVBAMMF0RQUyBEZXZlbG9wbWVudCBMZWFmIENBMRQwEgYDVQQLDAtEZXZlbG9wbWVudDEhMB8GA1UECgwYRGlyZWN0IFBheW1lbnQgU29sdXRpb25zMRIwEAYDVQQHDAlFbGxlcnNsaWUxETAPBgNVBAgMCEF1Y2tsYW5kMQswCQYDVQQGEwJOWjBZMBMGByqGSM49AgEGCCqGSM49AwEHA0IABLK8y6s67+EKTAQePM\/ADHad98X7V1RWyOjSaKIyWkVdlXkjhQQn3\/edkX5EVk55s0hkZ9om5ok2x1k4P8F\/UUajezB5MB0GA1UdDgQWBBRokCqGhhrOKaW3Os4aglOZ8lCVOjAfBgNVHSMEGDAWgBQCZM1to+73X0rnoYTnCtD09AsQgjAPBgNVHRMECDAGAQH\/AgEDMCYGCSqGSIb3Y2QGHQQZExdEUFMgRGV2ZWxvcG1lbnQgTGVhZiBDQTAKBggqhkjOPQQDAgNHADBEAiBaDzTrMHQeAs6JKCuFP7bXOUili\/\/0RgrPg88JteK7PQIgJnZItal4z7fWvwsHf\/Ph6oAvlGu+RwBFTXcI+ZOKkacwggL7MIICoaADAgECAgEBMAoGCCqGSM49BAMCMIG+MS0wKwYJKoZIhvcNAQkBFh5pZ29yLmtydXNjaEBwYXltZW50ZXhwcmVzcy5jb20xIDAeBgNVBAMMF0RQUyBEZXZlbG9wbWVudCBSb290IENBMRQwEgYDVQQLDAtEZXZlbG9wbWVudDEhMB8GA1UECgwYRGlyZWN0IFBheW1lbnQgU29sdXRpb25zMRIwEAYDVQQHDAlFbGxlcnNsaWUxETAPBgNVBAgMCEF1Y2tsYW5kMQswCQYDVQQGEwJOWjAeFw0xNjA5MjcwMDA4NDRaFw0yNjA5MjUwMDA4NDRaMIHGMS0wKwYJKoZIhvcNAQkBFh5pZ29yLmtydXNjaEBwYXltZW50ZXhwcmVzcy5jb20xKDAmBgNVBAMMH0RQUyBEZXZlbG9wbWVudCBJbnRlcm1lcmlhdGUgQ0ExFDASBgNVBAsMC0RldmVsb3BtZW50MSEwHwYDVQQKDBhEaXJlY3QgUGF5bWVudCBTb2x1dGlvbnMxEjAQBgNVBAcMCUVsbGVyc2xpZTERMA8GA1UECAwIQXVja2xhbmQxCzAJBgNVBAYTAk5aMFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEkikACkTnUK4UAB3FcB9YuxSenvTdl5nkw0vLu5OW1AGyirrYbRVd4gTeVA\/icf5xt76hEq0ZLY+WhPNZo4s6a6OBhTCBgjAdBgNVHQ4EFgQUAmTNbaPu919K56GE5wrQ9PQLEIIwHwYDVR0jBBgwFoAUi\/GlIj1Jzm46vZozy+dy9gOWuIswDwYDVR0TBAgwBgEB\/wIBAzAvBgoqhkiG92NkBgIOBCETH0RQUyBEZXZlbG9wbWVudCBJbnRlcm1lZGlhdGUgQ0EwCgYIKoZIzj0EAwIDSAAwRQIhAOp5cXRhq2x0J10iMKY4wGqljvqZ5kw4fCzmHNdJdm3fAiBR8DQsFoKIG4fVHuZQetO3wWGaxgD6haPYUVtaa9EvXjGCAiIwggIeAgEBMIHMMIHGMS0wKwYJKoZIhvcNAQkBFh5pZ29yLmtydXNjaEBwYXltZW50ZXhwcmVzcy5jb20xKDAmBgNVBAMMH0RQUyBEZXZlbG9wbWVudCBJbnRlcm1lcmlhdGUgQ0ExFDASBgNVBAsMC0RldmVsb3BtZW50MSEwHwYDVQQKDBhEaXJlY3QgUGF5bWVudCBTb2x1dGlvbnMxEjAQBgNVBAcMCUVsbGVyc2xpZTERMA8GA1UECAwIQXVja2xhbmQxCzAJBgNVBAYTAk5aAgEBMA0GCWCGSAFlAwQCAQUAoIHkMBgGCSqGSIb3DQEJAzELBgkqhkiG9w0BBwEwHAYJKoZIhvcNAQkFMQ8XDTIxMDMwMTIzMTk0NlowLwYJKoZIhvcNAQkEMSIEIBOxrRkt1cspvCLBr1WoyocmXzlYrNH3cxMNHqlyHP9JMHkGCSqGSIb3DQEJDzFsMGowCwYJYIZIAWUDBAEqMAsGCWCGSAFlAwQBFjALBglghkgBZQMEAQIwCgYIKoZIhvcNAwcwDgYIKoZIhvcNAwICAgCAMA0GCCqGSIb3DQMCAgFAMAcGBSsOAwIHMA0GCCqGSIb3DQMCAgEoMAoGCCqGSM49BAMCBEgwRgIhAPqWvF0PG+iQb+xU4IORjTmEXyzPsEaK1n3pzY0MbiOLAiEArPXVj23Ih2FS+1rqeYs1xJUE9P8zmPc7KXPe5IKPgoQ=",
                "version": "EC_v1"
            }
        },
        "billingContact":{
            "phoneNumber":"phoneNumber",
            "emailAddress":"emailAddress",
            "givenName":"givenName",
            "familyName":"familyName",
            "phoneticGivenName":"phoneticGivenName",
            "phoneticFamilyName":"phoneticFamilyName",
            "addressLines":"addressLines",
            "subLocality":"subLocality",
            "locality":"locality",
            "postalCode":"postalCode",
            "subAdministrativeArea":"subAdministrativeArea",
            "administrativeArea":"administrativeArea",
            "country":"country",
            "countryCode":"countryCode"
        },
        "shippingContact":{
            "phoneNumber":"phoneNumber",
            "emailAddress":"emailAddress",
            "givenName":"givenName",
            "familyName":"familyName",
            "phoneticGivenName":"phoneticGivenName",
            "phoneticFamilyName":"phoneticFamilyName",
            "addressLines":"addressLines",
            "subLocality":"subLocality",
            "locality":"locality",
            "postalCode":"postalCode",
            "subAdministrativeArea":"subAdministrativeArea",
            "administrativeArea":"administrativeArea",
            "country":"country",
            "countryCode":"countryCode"
        }
        }
    };
    return base;
});