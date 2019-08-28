<?php

use QUI\ERP\Order\Handler;
use QUI\ERP\Payments\Amazon\Payment;
use QUI\ERP\Payments\Amazon\AmazonPayException;
use QUI\Utils\Security\Orthos;

/**
 * Confirm an order with Amazon Pay
 *
 * @param string $orderReferenceId - Amazon OrderReferenceId; if false try to retrieve from Order
 * @param string $orderHash - Unique order hash to identify Order
 * @return bool - success
 * @throws AmazonPayException
 */
QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-amazon_ajax_confirmOrder',
    function ($orderReferenceId, $orderHash) {
        $orderHash = Orthos::clear($orderHash);

        try {
            $Order = Handler::getInstance()->getOrderByHash($orderHash);

            $Payment = new Payment();
            $Payment->confirmOrder($orderReferenceId, $Order);
        } catch (AmazonPayException $Exception) {
            QUI\System\Log::writeDebugException($Exception);
            throw $Exception;
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        return true;
    },
    ['orderReferenceId', 'orderHash']
);
