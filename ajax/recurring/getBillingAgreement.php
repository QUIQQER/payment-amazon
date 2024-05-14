<?php

/**
 * Get details of a Amazon Billing Agreement
 *
 * @param string $billingAgreementId - Amazon Billing Agreement ID
 * @return array|false Billing Agreement data
 * @throws AmazonPayException
 */

use QUI\ERP\Payments\Amazon\AmazonPayException;
use QUI\ERP\Payments\Amazon\Recurring\BillingAgreements;

QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-amazon_ajax_recurring_getBillingAgreement',
    function ($billingAgreementId) {
        try {
            $billingAgreement = BillingAgreements::getAmazonBillingAgreementData($billingAgreementId);
            $billingAgreement['quiqqer_data'] = BillingAgreements::getQuiqqerBillingAgreementData($billingAgreementId);

            return $billingAgreement;
        } catch (Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);
            return false;
        }
    },
    ['billingAgreementId'],
    ['Permission::checkAdminUser', 'quiqqer.payments.amazon.billing_agreements.view']
);
