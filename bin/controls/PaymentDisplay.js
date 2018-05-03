/**
 * PaymentDisplay for Amazon Pay
 *
 * @author Patrick Müller (www.pcsg.de)
 */
define('package/quiqqer/payment-amazon/bin/controls/PaymentDisplay', [

    'qui/controls/Control',
    'qui/controls/buttons/Button',

    'utils/Controls',
    'package/quiqqer/payments/bin/Handler',

    'Ajax',
    'Locale'

], function (QUIControl, QUIButton, QUIControlUtils, PaymentsHandler, QUIAjax, QUILocale) {
    "use strict";

    var pkg = 'quiqqer/payment-amazon';

    return new Class({

        Extends: QUIControl,
        Type   : 'package/quiqqer/payment-amazon/bin/controls/PaymentDisplay',

        Binds: [
            '$onImport',
            '$showAmazonPayBtn',
            '$onAmazonLoginReady',
            '$showAmazonWallet',
            '$showErrorMsg',
            '$onPayBtnClick'
        ],

        options: {
            sandbox   : true,
            sellerid  : '',
            clientid  : '',
            orderhash : '',
            successful: false
        },

        initialize: function (options) {
            this.parent(options);

            this.$orderReferenceId = false;
            this.$AuthBtnElm       = null;
            this.$WalletElm        = null;
            this.$PayBtn           = null;
            this.$MsgElm           = null;
            this.$OrderProcess     = null;

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
                var ScriptElm = document.getElement('script[src="' + widgetUrl + '"]');

                if (ScriptElm) {
                    amazon = null;
                    ScriptElm.destroy();
                }
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

                    onFinish: function() {
                        console.log("Amazon onFinish");
                    },

                    onSuccess: function() {
                        console.log("Amazon onSuccess");
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

            var waitForBtnElm = setInterval(function() {
                var AmazonBtnImg = self.$AuthBtnElm.getElement('img');

                if (!AmazonBtnImg) {
                    return;
                }

                if (!AmazonBtnImg.complete) {
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
                    QUILocale.get(pkg, 'controls.PaymentDisplay.wallet_info')
                );
            }

            this.$WalletElm.set('html', '');
            this.$WalletElm.removeClass('quiqqer-payment-amazon__hidden');

            var Options = {
                sellerId       : this.getAttribute('sellerid'),
                design         : {
                    designMode: 'responsive'
                },
                onPaymentSelect: function () {
                    self.$OrderProcess.resize();
                    self.$PayBtn.enable();
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

            if (!this.$PayBtn) {
                var PayBtnElm = this.getElm().getElement('#quiqqer-payment-amazon-btn-pay');

                this.$PayBtn = new QUIButton({
                    'class'  : 'btn-primary',
                    disabled : true,
                    text     : QUILocale.get(pkg, 'controls.PaymentDisplay.btn_pay.text', {
                        display_price: PayBtnElm.get('data-price')
                    }),
                    alt      : QUILocale.get(pkg, 'controls.PaymentDisplay.btn_pay.title', {
                        display_price: PayBtnElm.get('data-price')
                    }),
                    title    : QUILocale.get(pkg, 'controls.PaymentDisplay.btn_pay.title', {
                        display_price: PayBtnElm.get('data-price')
                    }),
                    textimage: 'fa fa-amazon',
                    events   : {
                        onClick: this.$onPayBtnClick
                    }
                }).inject(PayBtnElm);
            }

            // rendet wallet widget
            new OffAmazonPayments.Widgets.Wallet(Options).bind('quiqqer-payment-amazon-wallet');
        },

        /**
         * Start payment process
         *
         * @param {Object} Btn
         */
        $onPayBtnClick: function (Btn) {
            var self = this;

            Btn.disable();
            Btn.setAttribute('texticon', 'fa fa-spinner fa-spin');

            self.$WalletElm.addClass('quiqqer-payment-amazon__hidden');

            self.$OrderProcess.Loader.show(
                QUILocale.get(pkg, 'controls.PaymentDisplay.authorize_payment')
            );

            self.$authorizePayment().then(function (success) {
                if (success) {
                    self.$OrderProcess.next();
                    return;
                }

                self.$OrderProcess.Loader.hide();

                self.$showErrorMsg(
                    QUILocale.get(pkg, 'controls.PaymentDisplay.processing_error')
                );

                self.$showAmazonWallet(false);

                Btn.enable();
                Btn.setAttribute('textimage', 'fa fa-amazon');
            }, function (error) {
                self.$OrderProcess.Loader.hide();
                self.$showErrorMsg(error.getMessage());

                if (error.getAttribute('orderCancelled')) {
                    self.$orderReferenceId = false;
                }

                if (error.getAttribute('reRenderWallet')) {
                    self.$WalletElm.removeClass('quiqqer-payment-amazon__hidden');
                    self.$showAmazonWallet(false);

                    Btn.enable();
                    Btn.setAttribute('textimage', 'fa fa-amazon');

                    self.fireEvent('processingError', [self]);
                    return;
                }

                // sign out
                amazon.Login.logout();
                Btn.destroy();

                self.$showErrorMsg(
                    QUILocale.get(pkg, 'controls.PaymentDisplay.fatal_error')
                );

                new QUIButton({
                    text    : QUILocale.get(pkg, 'controls.PaymentDisplay.btn_reselect_payment.text'),
                    texticon: 'fa fa-credit-card',
                    events  : {
                        onClick: function () {
                            window.location.reload();
                        }
                    }
                }).inject(self.getElm().getElement('#quiqqer-payment-amazon-btn-pay'))
            });
        },

        /**
         * Start the payment process
         *
         * @return {Promise}
         */
        $authorizePayment: function () {
            var self = this;

            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_payment-amazon_ajax_authorizePayment', resolve, {
                    'package'       : pkg,
                    orderHash       : self.getAttribute('orderhash'),
                    orderReferenceId: self.$orderReferenceId,
                    onError         : reject
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
            return Payments.logPaymentsError(Error.getErrorMessage(), Error.getErrorCode());
        }
    });
});