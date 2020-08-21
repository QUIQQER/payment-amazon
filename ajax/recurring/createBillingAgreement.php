<?php

use QUI\ERP\Order\Handler;
use QUI\ERP\Payments\Amazon\Recurring\BillingAgreements;
use QUI\ERP\Payments\Amazon\AmazonPayException;
use QUI\Utils\Security\Orthos;

/**
 * Create / set up a billing agreement with Amazon Pay
 *
 * @param string $billingAgreementId - Amazon BillingAgreementId; if false try to retrieve from Order
 * @param string $orderHash - Unique order hash to identify Order
 * @return bool - success
 * @throws AmazonPayException
 */
QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-amazon_ajax_recurring_createBillingAgreement',
    function ($billingAgreementId, $orderHash) {
        $orderHash = Orthos::clear($orderHash);

        try {
            $Order = Handler::getInstance()->getOrderByHash($orderHash);

            BillingAgreements::setBillingAgreementDetails(Orthos::clear($billingAgreementId), $Order);
            BillingAgreements::confirmBillingAgreement($Order);
            BillingAgreements::validateBillingAgreement($Order);
        } catch (AmazonPayException $Exception) {
            QUI\System\Log::writeDebugException($Exception);
            throw $Exception;
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        return true;
    },
    ['billingAgreementId', 'orderHash']
);
