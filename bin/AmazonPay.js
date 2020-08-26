/**
 * Amazon Pay JavaScript API
 *
 * @author Patrick Müller (www.pcsg.de)
 */
define('package/quiqqer/payment-amazon/bin/AmazonPay', [

    'package/quiqqer/payment-amazon/bin/classes/AmazonPay'

], function (AmazonPay) {
    "use strict";
    return new AmazonPay();
});