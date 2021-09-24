/*browser:true*/
/*global define*/
define([ 'jquery', 'ko', 'Magento_Checkout/js/view/payment/default',
        'Windcave_Payments/js/action/set-payment',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_Customer/js/model/customer',
        'Magento_Checkout/js/model/quote',
        'Magento_Payment/js/model/credit-card-validation/credit-card-number-validator',
        'mage/translate',
        'mage/url',
        'Windcave_Payments/js/action/get-fusion-session',
        'Magento_Checkout/js/model/error-processor',
    ],

function($, ko, Component, setPaymentMethodAction, additionalValidators, customer, quote, cardNumberValidator, $t, urlBuilder, getFusionSessionAction, errorProcessor){
    'use strict';

    var pxFusionConfig = window.checkoutConfig.payment.windcave.pxfusion;

    pxFusionConfig.expiryMonths = [];
    pxFusionConfig.expiryYears = [];

    // convert 1 to 01
    function pad(source){
        if (source.length < 2)
            return "0" + source;
        return source;
    }

    var i, strTemp;
    for (i = 1; i <= 12; ++i) {
        strTemp = pad(i.toString());
        pxFusionConfig.expiryMonths.push(strTemp);
    }
    var today = new Date();
    var currentYear = today.getFullYear();
    var currentMonth = today.getMonth() + 1;
    for (i = 0; i < 16; ++i) {
        strTemp = pad((currentYear + i).toString());
        pxFusionConfig.expiryYears.push(strTemp);
    }

    var addBillCardEnabled = ko.observable(true);
    var rebillingTokenEnabled = ko.observable(false); // process with billing token
    var cardEnteringEnabled = ko.observable(true);

    var paymentOption = ko.observable("withoutRebillToken");

    var cardNumber = ko.observable();
    var cardHolderName = ko.observable();
    var expiryMonth = ko.observable();
    var expiryYear = ko.observable();
    var cvc = ko.observable();

    var cvcForRebilling = ko.observable();

    // http://stackoverflow.com/questions/19590607/set-checkbox-when-radio-button-changes-with-knockout


    return Component.extend({
        creditCardType : ko.observable(),
        cardNumber : cardNumber,
        cardHolderName : cardHolderName,
        expiryMonth : expiryMonth,
        expiryYear : expiryYear,
        expiryMonths : pxFusionConfig.expiryMonths,
        expiryYears : pxFusionConfig.expiryYears,
        cvc : cvc,

        showCardOptions: pxFusionConfig.showCardOptions,
        isRebillEnabled: pxFusionConfig.isRebillEnabled,
        placeOrderButtonTitle: pxFusionConfig.placeOrderButtonTitle,

        containsSavedCards: pxFusionConfig.savedCards.length > 0,
        savedCards: pxFusionConfig.savedCards,
        cardEnteringEnabled: cardEnteringEnabled,

        paymentOption: paymentOption,
        addBillCardEnabled: addBillCardEnabled,
        enableAddBillCard: ko.observable(),

        rebillingTokenEnabled: rebillingTokenEnabled,
        billingId: ko.observable(),
        cvcForRebilling: cvcForRebilling,
        requireCvcForRebilling: !!pxFusionConfig.requireCvcForRebilling,

        redirectAfterPlaceOrder: false,

        defaults : {
            template : 'Windcave_Payments/payment/dps-pxfusion'
        },
        
        initObservable: function () {
            this._super()
                .observe([
                    'creditCardType',
                    'expiryYear',
                    'expiryMonth',
                    'cardNumber',
                    'cvc',
                    'cardHolderName'
                ]);
            return this;
        },
        
        initialize: function() {
            var self = this;
            this._super();
            
            self.paymentOption.subscribe(self.paymentOptionChanged, self);

            //self.expiryMonth(pad(currentMonth.toString()));
            //self.expiryYear(currentYear.toString());
            
            //Set credit card number to credit card data object
            this.cardNumber.subscribe(function(value) {
                var result;

                if (value == '' || value == null) {
                    return false;
                }

                var fixedValue = self.formatCardNumber(value);
                if (value != fixedValue)
                    self.cardNumber(fixedValue);

                result = cardNumberValidator(fixedValue);

                if (!result.isPotentiallyValid && !result.isValid) {
                    return false;
                }

                if (result.isValid) {
                    self.creditCardType(result.card.type);
                }
            });
        },
        
        paymentOptionChanged: function() {
            const WITH_REBILL_TOKEN = "withRebillToken";
            const WITHOUT_REBILL_TOKEN = "withoutRebillToken";

            var self = this;

            var paymentOptionValue = self.paymentOption();
            self.addBillCardEnabled(paymentOptionValue == WITHOUT_REBILL_TOKEN);
            self.rebillingTokenEnabled(paymentOptionValue == WITH_REBILL_TOKEN);
            self.cardEnteringEnabled(paymentOptionValue == WITHOUT_REBILL_TOKEN);

            if (paymentOptionValue == WITH_REBILL_TOKEN) {
                self.cardNumber("");
                self.cardHolderName("");
                self.expiryMonth(pad(currentMonth.toString()));
                self.expiryYear(currentYear.toString());
            }
            else {
                self.cvcForRebilling("");
            }
        },

        getCvvImageUrl: function() {
            return "";
        },
        getCvvImageHtml: function() {
            return '<img src="' + this.getCvvImageUrl()
                + '" alt="' + $t('Card Verification Number Visual Reference')
                + '" title="' + $t('Card Verification Number Visual Reference')
                + '" />';
        },

        getCardData : function(sessionId){
            return {
                cardNumber : this.cardNumber(),
                cvc : this.cvc(),
                cardHolderName : this.cardHolderName(),
                expiryMonth : this.expiryMonth(),
                expiryYear : this.expiryYear() - 2000,
                sessionId : sessionId ? sessionId : pxFusionConfig.sessionId
            };
        },

        getRebillingData: function(sessionId) {
            return {
                cvc : this.cvcForRebilling(),
                sessionId : sessionId ? sessionId : pxFusionConfig.sessionId
            };
        },

        getData: function () {
            var parent = this._super();

            var additionalData = {
                windcave_enableAddBillCard: this.enableAddBillCard(),
                windcave_useSavedCard: this.rebillingTokenEnabled(),
                windcave_billingId: this.rebillingTokenEnabled() ? this.billingId() : "",
            };

            var result = $.extend(true, parent, {
                'additional_data': additionalData
            });
            

            return result;
        },

        formatCardNumber: function(value) {
          var formattedText = value.replace(/\D/g,'');
          
          if (formattedText.length > 0) {
            formattedText = formattedText.match(new RegExp('.{1,4}', 'g')).join(' ');
          }

          return formattedText;
        },
        
        numberOnlyInput: function(data, event, isCardNumber) {
            var value = event.target.value;
            if (isCardNumber)
                value = data.formatCardNumber(value);
            else 
                value = value.replace(/\D/g,'');
            event.target.value = value;
        },

        postCreditCardData : function(postData){
            // http://stackoverflow.com/questions/8003089/dynamically-create-and-submit-form
            // create a form to submit. is it any better way?
            //var form = $(document.createElement('form'));
            var form = $("<form></form>");
            form.attr("action", pxFusionConfig.postUrl);
            form.attr("method", "POST");

            var cardNumberInput = $("<input>").attr("type", "hidden").attr(
                    "name", "CardNumber").val(postData.cardNumber.replace(/\D/g,''));
            form.append(cardNumberInput);

            var cvcInput = $("<input>").attr("type", "hidden").attr("name",
                    "Cvc2").val(postData.cvc);
            form.append(cvcInput);
            
            var cardHolderNameInput = $("<input>").attr("type", "hidden").attr(
                    "name", "CardHolderName").val(
                    postData.cardHolderName);
            form.append(cardHolderNameInput);

            var expiryMonthInput = $("<input>").attr("type", "hidden").attr(
                    "name", "ExpiryMonth").val(postData.expiryMonth);
            form.append(expiryMonthInput);
            
            var expiryYearInput = $("<input>").attr("type", "hidden").attr(
                    "name", "ExpiryYear").val(postData.expiryYear);
            form.append(expiryYearInput);

            var sessionIdInput = $("<input>").attr("type", "hidden").attr(
                    "name", "SessionId").val(postData.sessionId);
            form.append(sessionIdInput);
            
            // must add a submit button in the form, other not submit in Firefox.
            // http://stackoverflow.com/questions/31265218/cannot-submit-form-from-javascript-on-firefox
            var submitButton = $("<button>").attr("type", "submit").attr(
                    "name", "ClickMe").val("Click Me");
            form.append(submitButton);
            
            form.appendTo('body');

            form.submit();

            return;
        },

        postRebillingData : function(postData){
            var form = $("<form></form>");
            form.attr("action", pxFusionConfig.postUrl);
            form.attr("method", "POST");

            var cvcInput = $("<input>").attr("type", "hidden").attr("name",
                    "Cvc2").val(postData.cvc);
            form.append(cvcInput);

            var sessionIdInput = $("<input>").attr("type", "hidden").attr(
                    "name", "SessionId").val(postData.sessionId);
            form.append(sessionIdInput);
            
            var submitButton = $("<button>").attr("type", "submit").attr(
                    "name", "ClickMe").val("Click Me");
            form.append(submitButton);
            
            form.appendTo('body');

            form.submit();

            return;
        },

        validate: function () {
            var $form = $('#' + this.getCode() + '_form');
            return $form.validation() && $form.validation('isValid');
        },


        afterPlaceOrder: function() {
            const SESSION_TYPE_PAYMENT = "Payment";
            const SESSION_TYPE_REBILLING = "Rebilling";
            const SESSION_TYPE_REBILLING_WITH_CVC = "RebillingWithCvc";

            var that = this;
            getFusionSessionAction(this.messageContainer, "pxfusion", function(sessionData) {
                var sessionDataObj = JSON.parse(sessionData);
                if (sessionDataObj.type == SESSION_TYPE_PAYMENT) {
                    that.postCreditCardData(that.getCardData(sessionDataObj.sessionId));
                    return false;
                }

                if (sessionDataObj.type == SESSION_TYPE_REBILLING_WITH_CVC) {
                    that.postRebillingData(that.getRebillingData(sessionDataObj.sessionId));
                    return false;
                }

                if (sessionDataObj.type == SESSION_TYPE_REBILLING) {
                    if (sessionDataObj.authorized == true) {
                        var url = urlBuilder.build("checkout/onepage/success");
                        window.location.href = url;
                    }
                    else {
                        var errorResponse = { status: 400, responseText: JSON.stringify({ message: sessionDataObj.message }) };
                        errorProcessor.process(errorResponse, that.messageContainer);

                        var url = urlBuilder.build("checkout/#payment");
                        window.location.href = url;
                    }
                }
                return true;
            });
        }
    });
});
