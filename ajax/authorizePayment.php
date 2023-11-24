<?php

/**
 * Authorize Amazon Pay payment for an Order
 *
 * @param string|false $orderReferenceId - Amazon OrderReferenceId; if false try to retrieve from Order
 * @param string $orderHash - Unique order hash to identify Order
 * @return bool - success
 * @throws AmazonPayException
 */

use QUI\ERP\Order\Handler;
use QUI\ERP\Payments\Amazon\AmazonPayException;
use QUI\ERP\Payments\Amazon\Payment;
use QUI\Utils\Security\Orthos;

QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-amazon_ajax_authorizePayment',
    function ($orderReferenceId, $orderHash) {
        $orderHash = Orthos::clear($orderHash);

        try {
            $Order = Handler::getInstance()->getOrderByHash($orderHash);

            $Payment = new Payment();
            $Payment->authorizePayment($orderReferenceId, $Order);
        } catch (AmazonPayException $Exception) {
            throw $Exception;
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        return true;
    },
    ['orderReferenceId', 'orderHash']
);
