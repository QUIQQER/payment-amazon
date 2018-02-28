/**
 * PaymentDisplay for Amazon Pay
 *
 * @author Patrick Müller (www.pcsg.de)
 */
define('package/quiqqer/payment-amazon/bin/controls/PaymentDisplay', [

    'qui/controls/Control',
    'qui/controls/loader/Loader',
    'qui/controls/buttons/Button',

    'Ajax',
    'Locale'

], function (QUIControl, QUILoader, QUIButton, QUIAjax, QUILocale) {
    "use strict";

    var pkg = 'quiqqer/payment-amazon';

    return new Class({

        Extends: QUIControl,
        Type   : 'package/quiqqer/payment-amazon/bin/controls/PaymentDisplay',

        Binds: [
            '$onImport',
            '$onAmazonPaymentsReady',
            '$onAmazonLoginReady',
            '$showAmazonWallet',
            '$showErrorMsg'
        ],

        options: {
            sandbox  : true,
            sellerid : '',
            clientid : '',
            orderhash: ''
        },

        initialize: function (options) {
            this.parent(options);

            this.Loader            = new QUILoader();
            this.$accessToken      = false;
            this.$orderReferenceId = false;
            this.$AuthBtnElm       = null;
            this.$WalletElm        = null;
            this.$PayBtn           = null;
            this.$MsgElm           = null;

            this.addEvents({
                onImport: this.$onImport
            });
        },

        /**
         * Event: onImport
         */
        $onImport: function () {
            var Elm = this.getElm();

            this.Loader.inject(Elm);

            this.$MsgElm     = Elm.getElement('.quiqqer-payment-amazon-message');
            this.$AuthBtnElm = Elm.getElement('#quiqqer-payment-amazon-btn');
            this.$WalletElm  = Elm.getElement('#quiqqer-payment-amazon-wallet');

            if (typeof window.onAmazonPaymentsReady === 'undefined') {
                window.onAmazonPaymentsReady = this.$onAmazonPaymentsReady;
            }

            if (typeof window.onAmazonLoginReady === 'undefined') {
                window.onAmazonLoginReady = this.$onAmazonLoginReady;
            }

            this.Loader.show();

            // loader Amazon JavaScript widgets
            var widgetUrl = "https://static-eu.payments-amazon.com/OffAmazonPayments/eur/sandbox/lpa/js/Widgets.js";

            if (!this.getAttributes('sandbox')) {
                widgetUrl = ''; // @todo LIVE widget url
            }

            if (typeof amazon !== 'undefined') {
                this.$onAmazonWidgetsLoaded();
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
            amazon.Login.setClientId(this.getAttribute('clientid'));
        },

        /**
         * Execute if Amazon Pay has loaded
         */
        $onAmazonPaymentsReady: function () {
            var self = this;

            this.Loader.hide();

            OffAmazonPayments.Button(
                'quiqqer-payment-amazon-btn',
                this.getAttribute('sellerid'),
                {
                    type : 'PwA',
                    color: 'Gold',
                    size : 'x-large',
                    //language: 'LANGUAGE_PARAMETER'

                    authorization: function () {
                        var authRequest = amazon.Login.authorize({
                            popup: true,
                            scope: 'payments:widget'
                        }, function (Response) {
                            if (Response.error) {

                                return;
                            }

                            self.$accessToken = Response.access_token;
                            self.$showAmazonWallet();
                        });
                    }
                }
            );
        },

        /**
         * Show Amazon Pay Wallet widget
         */
        $showAmazonWallet: function () {
            var self = this;

            var Options = {
                sellerId       : this.getAttribute('sellerid'),
                design         : {
                    designMode: 'responsive'
                },
                onPaymentSelect: function () {
                    self.$PayBtn.enable();
                },
                onError        : function (error) {
                    self.$showErrorMsg(error.getErrorMessage());
                }
            };

            if (!this.$orderReferenceId) {
                Options.onOrderReferenceCreate = function (orderReference) {
                    self.$orderReferenceId = orderReference.getAmazonOrderReferenceId();
                }
            } else {
                Options.amazonOrderReferenceId = this.$orderReferenceId;
            }

            if (!this.$PayBtn) {
                this.$PayBtn = new QUIButton({
                    disabled: true,
                    text    : QUILocale.get(pkg, 'controls.PaymentDisplay.btn_pay.text'),
                    texticon: 'fa fa-amazon',
                    events  : {
                        onClick: function () {
                            self.Loader.show();

                            self.$processPayment().then(function (success) {
                                self.Loader.hide();

                                if (!success) {
                                    self.$showErrorMsg(
                                        QUILocale.get(pkg, 'controls.PaymentDisplay.processing_error')
                                    );

                                    return;
                                }

                                // @todo payment success
                                self.$AuthBtnElm.addClass('quiqqer-payment-amazon__hidden');

                            }, function (error) {
                                self.Loader.hide();
                                self.$showErrorMsg(error.getMessage());

                                if (error.getAttribute('reRenderWallet')) {
                                    self.$showAmazonWallet();
                                }
                            });
                        }
                    }
                }).inject(this.getElm().getElement('#quiqqer-payment-amazon-btn-pay'));
            }

            new OffAmazonPayments.Widgets.Wallet(Options).bind('quiqqer-payment-amazon-wallet');
        },

        /**
         * Start the payment process
         *
         * @return {Promise}
         */
        $processPayment: function () {
            var self = this;

            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_payment-amazon_ajax_processPayment', resolve, {
                    'package'       : pkg,
                    orderHash       : self.getAttribute('orderhash'),
                    orderReferenceId: self.$orderReferenceId,
                    accessToken     : self.$accessToken,
                    onError         : reject
                })
            });
        },

        /**
         * Show error msg
         *
         * @param msg
         */
        $showErrorMsg: function (msg) {
            this.$MsgElm.set(
                'html',
                '<p class="message-error">' + msg + '</p>'
            );
        }
    });
});