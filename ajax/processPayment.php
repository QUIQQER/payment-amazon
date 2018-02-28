<?php

use QUI\ERP\Order\Handler;
use QUI\ERP\Payments\Amazon\Payment;
use QUI\ERP\Payments\Amazon\AmazonPayException;

/**
 * Process Amazon Pay payment
 *
 * @return bool - success
 * @throws AmazonPayException
 */
QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-amazon_ajax_processPayment',
    function ($accessToken, $orderReferenceId, $orderHash) {
        try {
            $Order = Handler::getInstance()->getOrderByHash($orderHash);

            $Payment = new Payment();
            $Payment->startPaymentProcess($accessToken, $orderReferenceId, $Order);
        } catch (AmazonPayException $Exception) {
            throw $Exception;
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        return true;
    },
    array('accessToken', 'orderReferenceId', 'orderHash')
);
