<?php

use QUI\ERP\Order\Handler;
use QUI\ERP\Payments\Amazon\Payment;
use QUI\ERP\Payments\Amazon\AmazonPayException;
use QUI\Utils\Security\Orthos;
use QUI\ERP\Payments\Amazon\Provider;

/**
 * Authorize Amazon Pay payment for an Order
 *
 * @param string|false $orderReferenceId - Amazon OrderReferenceId; if false try to retrieve from Order
 * @param string $orderHash - Unique order hash to identify Order
 * @return bool - success
 * @throws AmazonPayException
 */
QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-amazon_ajax_authorizePayment',
    function ($orderReferenceId, $orderHash) {
        $orderHash = Orthos::clear($orderHash);

        try {
            $Order = Handler::getInstance()->getOrderByHash($orderHash);

            if (!$orderReferenceId) {
                $orderReferenceId = $Order->getPaymentDataEntry(Payment::ATTR_AMAZON_ORDER_REFERENCE_ID);

                if (!$orderReferenceId) {
                    // OrderReferenceId has not yet been set to the Order
                    // and must be (re-)generated by loading the Amazon Pay
                    // Wallet and/or Address widget(s)
                    return false;
                }
            }

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
    array('orderReferenceId', 'orderHash')
);