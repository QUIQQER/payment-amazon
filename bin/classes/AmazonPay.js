/**
 * Amazon Pay JavaScript API
 *
 * @author Patrick Müller (www.pcsg.de)
 */
define('package/quiqqer/payment-amazon/bin/classes/AmazonPay', [

    'Ajax'

], function (QUIAjax) {
    "use strict";

    var pkg = 'quiqqer/payment-amazon';

    return new Class({

        Type: 'package/quiqqer/payment-amazon/bin/classes/AmazonPay',

        /**
         * Get Amazon Billing Agreement details
         *
         * @param {String} billingAgreementId - Amazon Billing Agreement ID
         * @return {Promise}
         */
        getBillingAgreement: function (billingAgreementId) {
            return new Promise(function (resolve, reject) {
                QUIAjax.get('package_quiqqer_payment-amazon_ajax_recurring_getBillingAgreement', resolve, {
                    'package'         : pkg,
                    billingAgreementId: billingAgreementId,
                    onError           : reject
                })
            });
        },

        /**
         * Get Amazon Billing Agreement list
         *
         * @param {Object} SearchParams - Grid search params
         * @return {Promise}
         */
        getBillingAgreementList: function (SearchParams) {
            return new Promise(function (resolve, reject) {
                QUIAjax.get('package_quiqqer_payment-amazon_ajax_recurring_getBillingAgreementList', resolve, {
                    'package'   : pkg,
                    searchParams: JSON.encode(SearchParams),
                    onError     : reject
                })
            });
        },

        /**
         * Cancel a Amazon Billing Agreement
         *
         * @param {String} billingAgreementId - Amazon Billing Agreement ID
         * @return {Promise}
         */
        cancelBillingAgreement: function (billingAgreementId) {
            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_payment-amazon_ajax_recurring_cancelBillingAgreement', resolve, {
                    'package'         : pkg,
                    billingAgreementId: billingAgreementId,
                    onError           : reject
                })
            });
        }
    });
});