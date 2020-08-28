/**
 * PaymentDisplay for Amazon
 *
 * @author Patrick Müller (www.pcsg.de)
 */
define('package/quiqqer/payment-amazon/bin/controls/recurring/PaymentDisplay', [

    'qui/controls/Control',
    'qui/controls/buttons/Button',

    'utils/Controls',

    'Ajax',
    'Locale',

    'css!package/quiqqer/payment-amazon/bin/controls/recurring/PaymentDisplay.css'

], function (QUIControl, QUIButton, QUIControlUtils, QUIAjax, QUILocale) {
    "use strict";

    var pkg = 'quiqqer/payment-amazon';

    return new Class({

        Extends: QUIControl,
        Type   : 'package/quiqqer/payment-amazon/bin/controls/recurring/PaymentDisplay',

        Binds: [
            '$onImport',
            '$showAmazonPayBtn',
            '$onAmazonLoginReady',
            '$showAmazonWallet',
            '$showErrorMsg',
            '$onPayBtnClick'
        ],

        options: {
            sandbox        : true,
            sellerid       : '',
            clientid       : '',
            orderhash      : '',
            successful     : false,
            displayprice   : '',
            displayinterval: ''
        },

        initialize: function (options) {
            this.parent(options);

            this.$orderReferenceId   = false;
            this.$billingAgreementId = false;
            this.$AuthBtnElm         = null;
            this.$WalletElm          = null;
            this.$ConsentElm         = null;
            this.$PayBtn             = null;
            this.$MsgElm             = null;
            this.$OrderProcess       = null;
            this.$confirmationFlow   = null;  // Reference to Amazon Pay Confirmation Flow
            this.$consentBoxShown    = false;

            this.addEvents({
                onImport: this.$onImport
            });
        },

        /**
         * Event: onImport
         */
        $onImport: function () {
            var self = this;
            var Elm  = this.getElm();

            if (Elm.getElement('.message-error')) {
                (function () {
                    self.fireEvent('processingError', [self]);
                }).delay(2000);
                return;
            }

            if (Elm.getElement('.message-error')) {
                return;
            }

            this.$MsgElm     = Elm.getElement('.quiqqer-payment-amazon-message');
            this.$AuthBtnElm = Elm.getElement('#quiqqer-payment-amazon-btn');
            this.$WalletElm  = Elm.getElement('#quiqqer-payment-amazon-wallet');
            this.$ConsentElm = Elm.getElement('#quiqqer-payment-amazon-consent');

            // set random id for AuthBtn to enable re-rending of Amazon Btn Widget
            this.$AuthBtnElm.set('id', this.$AuthBtnElm.get('id') + '-' + Math.floor((Math.random() * 1000000) + 1));

            this.$showMsg(
                QUILocale.get(pkg, 'controls.PaymentDisplay.info')
            );

            QUIControlUtils.getControlByElement(
                Elm.getParent('[data-qui="package/quiqqer/order/bin/frontend/controls/OrderProcess"]')
            ).then(function (OrderProcess) {
                self.$OrderProcess = OrderProcess;

                if (self.getAttribute('successful')) {
                    OrderProcess.next();
                    return;
                }

                self.$loadAmazonWidgets();
            });
        },

        /**
         * Load Amazon Pay widgets
         */
        $loadAmazonWidgets: function () {
            var widgetUrl = "https://static-eu.payments-amazon.com/OffAmazonPayments/eur/sandbox/lpa/js/Widgets.js";

            if (!this.getAttribute('sandbox')) {
                widgetUrl = 'https://static-eu.payments-amazon.com/OffAmazonPayments/eur/lpa/js/Widgets.js';
            }

            this.$OrderProcess.Loader.show();

            window.onAmazonPaymentsReady = this.$showAmazonPayBtn;
            window.onAmazonLoginReady    = this.$onAmazonLoginReady;

            if (typeof amazon !== 'undefined') {
                this.$showAmazonPayBtn();
                return;
            }

            new Element('script', {
                async: "async",
                src  : widgetUrl
            }).inject(document.body);
        },

        /**
         * Execute if Amazon Login has loaded
         */
        $onAmazonLoginReady: function () {
            if (typeof amazon === 'undefined') {
                return;
            }

            amazon.Login.setClientId(this.getAttribute('clientid'));
        },

        /**
         * Show Amazon Pay authentication widget (btn)
         */
        $showAmazonPayBtn: function () {
            var self = this;

            // re-display if button was previously rendered and hidden
            this.$AuthBtnElm.removeClass('quiqqer-payment-amazon__hidden');

            OffAmazonPayments.Button(
                this.$AuthBtnElm.get('id'),
                this.getAttribute('sellerid'),
                {
                    type : 'PwA',
                    color: this.$AuthBtnElm.get('data-color'),
                    size : this.$AuthBtnElm.get('data-size'),

                    authorization: function () {
                        amazon.Login.authorize({
                            popup: true,
                            scope: 'payments:widget'
                        }, function (Response) {
                            if (Response.error) {
                                self.$showErrorMsg(
                                    QUILocale.get(pkg, 'controls.PaymentDisplay.login_error')
                                );

                                self.fireEvent('processingError', [self]);
                                return;
                            }

                            self.$accessToken = Response.access_token;

                            self.$AuthBtnElm.addClass('quiqqer-payment-amazon__hidden');
                            self.$showAmazonWallet(true);
                        });
                    },

                    onError: function (Error) {
                        switch (Error.getErrorCode()) {
                            // handle errors on the shop side (most likely misconfiguration)
                            case 'InvalidAccountStatus':
                            case 'InvalidSellerId':
                            case 'InvalidParameterValue':
                            case 'MissingParameter':
                            case 'UnknownError':
                                self.$AuthBtnElm.addClass('quiqqer-payment-amazon__hidden');

                                self.$showErrorMsg(
                                    QUILocale.get(pkg, 'controls.PaymentDisplay.configuration_error')
                                );

                                self.$logError(Error);
                                break;

                            default:
                                self.$showErrorMsg(
                                    QUILocale.get(pkg, 'controls.PaymentDisplay.login_error')
                                );
                        }

                        self.fireEvent('processingError', [self]);
                    }
                }
            );

            this.$OrderProcess.Loader.show();

            var waitForBtnElm = setInterval(function () {
                var AmazonBtnImg = self.$AuthBtnElm.getElement('img');

                if (!AmazonBtnImg) {
                    return;
                }

                if (!AmazonBtnImg.complete && !AmazonBtnImg.getSize().y) {
                    return;
                }

                clearInterval(waitForBtnElm);

                self.$OrderProcess.resize();
                self.$OrderProcess.Loader.hide();
            }, 200);
        },

        /**
         * Show Amazon Pay Wallet widget
         *
         * @param {Boolean} [showInfoMessage] - Show info message
         */
        $showAmazonWallet: function (showInfoMessage) {
            var self = this;

            if (showInfoMessage) {
                this.$showMsg(
                    QUILocale.get(pkg, 'controls.recurring.PaymentDisplay.wallet_info', {
                        price   : this.getAttribute('displayprice'),
                        interval: this.getAttribute('displayinterval')
                    })
                );
            }

            this.$WalletElm.set('html', '');
            this.$WalletElm.removeClass('quiqqer-payment-amazon__hidden');

            var Options = {
                sellerId       : this.getAttribute('sellerid'),
                design         : {
                    designMode: 'responsive'
                },
                agreementType  : 'BillingAgreement',
                onReady        : function (billingAgreement) {
                    if (!self.$billingAgreementId) {
                        self.$billingAgreementId = billingAgreement.getAmazonBillingAgreementId();
                    }

                    self.$OrderProcess.resize();
                },
                onPaymentSelect: function (billingAgreement) {
                    self.$showRecurringPaymentConsent();
                },
                onError        : function (Error) {
                    switch (Error.getErrorCode()) {
                        // handle errors on the shop side (most likely misconfiguration)
                        case 'InvalidAccountStatus':
                        case 'InvalidSellerId':
                        case 'InvalidParameterValue':
                        case 'MissingParameter':
                        case 'UnknownError':
                            self.$showErrorMsg(
                                QUILocale.get(pkg, 'controls.PaymentDisplay.configuration_error')
                            );
                            break;

                        case 'AddressNotModifiable':
                        case 'BuyerNotAssociated':
                        case 'BuyerSessionExpired':
                        case 'PaymentMethodNotModifiable':
                        case 'StaleOrderReference':
                            self.$AuthBtnElm.removeClass('quiqqer-payment-amazon__hidden');
                            self.$orderReferenceId = false;
                            self.$showErrorMsg(Error.getErrorMessage());
                            break;

                        default:
                            self.$showErrorMsg(
                                QUILocale.get(pkg, 'controls.PaymentDisplay.wallet_error')
                            );
                    }

                    self.$PayBtn.destroy();
                    self.fireEvent('processingError', [self]);

                    self.$logError(Error);
                }
            };

            if (!this.$orderReferenceId) {
                Options.onOrderReferenceCreate = function (orderReference) {
                    self.$orderReferenceId = orderReference.getAmazonOrderReferenceId();
                }
            } else {
                Options.amazonOrderReferenceId = this.$orderReferenceId;
            }

            var PayBtnElm = this.getElm().getElement('#quiqqer-payment-amazon-btn-pay');

            // rendet wallet widget
            var WalletWidget = new OffAmazonPayments.Widgets.Wallet(Options);
            WalletWidget.setPresentmentCurrency(PayBtnElm.get('data-currencycode'));
            WalletWidget.bind('quiqqer-payment-amazon-wallet');
        },

        /**
         * Show Amazon Pay consent widget for recurring payment
         */
        $showRecurringPaymentConsent: function () {
            if (this.$consentBoxShown) {
                return;
            }

            var self = this;

            this.$ConsentElm.set('html', '');
            this.$ConsentElm.removeClass('quiqqer-payment-amazon__hidden');

            this.$OrderProcess.Loader.show();

            var handleConsentStatus = function (billingAgreementConsentStatus) {
                var buyerBillingAgreementConsentStatus = billingAgreementConsentStatus.getConsentStatus();

                if (!buyerBillingAgreementConsentStatus || buyerBillingAgreementConsentStatus !== 'true') {
                    self.$showErrorMsg(
                        QUILocale.get(pkg, 'controls.recurring.PaymentDisplay.consent_denied')
                    );

                    self.fireEvent('processingError', [self]);
                    self.$PayBtn.disable();
                    return;
                }

                self.$PayBtn.enable();
            }

            var Options = {
                sellerId : this.getAttribute('sellerid'),
                design   : {
                    designMode: 'responsive'
                },
                onReady  : function (billingAgreementConsentStatus) {
                    self.$OrderProcess.Loader.hide();

                    var buyerBillingAgreementConsentStatus = billingAgreementConsentStatus.getConsentStatus();

                    if (buyerBillingAgreementConsentStatus === 'true') {
                        self.$PayBtn.enable();
                    }
                },
                onConsent: handleConsentStatus,
                onError  : function (Error) {
                    switch (Error.getErrorCode()) {
                        // handle errors on the shop side (most likely misconfiguration)
                        case 'InvalidAccountStatus':
                        case 'InvalidSellerId':
                        case 'InvalidParameterValue':
                        case 'MissingParameter':
                        case 'UnknownError':
                            self.$showErrorMsg(
                                QUILocale.get(pkg, 'controls.PaymentDisplay.configuration_error')
                            );
                            break;

                        case 'AddressNotModifiable':
                        case 'BuyerNotAssociated':
                        case 'BuyerSessionExpired':
                        case 'PaymentMethodNotModifiable':
                        case 'StaleOrderReference':
                            self.$AuthBtnElm.removeClass('quiqqer-payment-amazon__hidden');
                            self.$orderReferenceId = false;
                            self.$showErrorMsg(Error.getErrorMessage());
                            break;

                        default:
                            self.$showErrorMsg(
                                QUILocale.get(pkg, 'controls.PaymentDisplay.wallet_error')
                            );
                    }

                    //self.$PayBtn.destroy();
                    self.fireEvent('processingError', [self]);

                    self.$logError(Error);
                }
            };

            if (!this.$orderReferenceId) {
                Options.onOrderReferenceCreate = function (orderReference) {
                    self.$orderReferenceId = orderReference.getAmazonOrderReferenceId();
                }
            } else {
                Options.amazonOrderReferenceId = this.$orderReferenceId;
            }

            // rendet wallet widget
            var ConsentWidget = new OffAmazonPayments.Widgets.Consent(Options);
            //ConsentWidget.setPresentmentCurrency(PayBtnElm.get('data-currencycode'));
            ConsentWidget.bind('quiqqer-payment-amazon-consent');

            this.$consentBoxShown = true;

            if (!this.$PayBtn) {
                this.$PayBtn = new QUIButton({
                    'class'  : 'btn-primary',
                    disabled : true,
                    text     : QUILocale.get(pkg, 'controls.recurring.PaymentDisplay.btn_pay.text'),
                    title    : QUILocale.get(pkg, 'controls.recurring.PaymentDisplay.btn_pay.title'),
                    textimage: 'fa fa-amazon',
                    events   : {
                        onClick: this.$onPayBtnClick
                    }
                }).inject(this.getElm().getElement('#quiqqer-payment-amazon-btn-pay'));
            }
        },

        /**
         * Event: on pay btn click
         */
        $onPayBtnClick: function () {
            var self = this;

            this.$OrderProcess.Loader.show();

            this.$createBillingAgreement().then(function (success) {
                self.$OrderProcess.Loader.hide();

                if (!success) {
                    self.$showAmazonWallet(false);
                    self.$showErrorMsg(
                        QUILocale.get(pkg, 'controls.recurring.PaymentDisplay.billing_agreement_failed')
                    );

                    self.fireEvent('processingError', [self]);

                    return;
                }

                self.$OrderProcess.next();
            }, function (e) {
                self.$OrderProcess.Loader.hide();

                self.$showErrorMsg(e.getMessage());
                self.$showAmazonWallet(false);
                self.fireEvent('processingError', [self]);
            });
        },

        /**
         * Create / set up a BillingAgreement
         *
         * @return {Promise}
         */
        $createBillingAgreement: function () {
            var self = this;

            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_payment-amazon_ajax_recurring_createBillingAgreement', resolve, {
                    'package'         : pkg,
                    orderHash         : self.getAttribute('orderhash'),
                    billingAgreementId: self.$billingAgreementId,
                    onError           : reject
                })
            });
        },

        /**
         * Show error msg
         *
         * @param {String} msg
         */
        $showErrorMsg: function (msg) {
            this.$MsgElm.set(
                'html',
                '<p class="message-error">' + msg + '</p>'
            );
        },

        /**
         * Show normal msg
         *
         * @param {String} msg
         */
        $showMsg: function (msg) {
            this.$MsgElm.set(
                'html',
                '<p>' + msg + '</p>'
            );
        },

        /**
         * Log an Amazon Pay widget/processing error
         *
         * @param {Object} Error - Amazon Pay widget error
         * @return {Promise}
         */
        $logError: function (Error) {
            return PaymentsHandler.logPaymentsError(Error.getErrorMessage(), Error.getErrorCode());
        }
    });
});