<?php

use QUI\ERP\Payments\Amazon\AmazonPayException;
use QUI\ERP\Payments\Amazon\Recurring\BillingAgreements;

/**
 * Cancel a Amazon Billing Agreement
 *
 * @param string $billingAgreementId - Amazon Billing Agreement ID
 * @return void
 * @throws AmazonPayException
 */
QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-amazon_ajax_recurring_cancelBillingAgreement',
    function ($billingAgreementId) {
        BillingAgreements::cancelBillingAgreement($billingAgreementId);

        QUI::getMessagesHandler()->addSuccess(
            QUI::getLocale()->get(
                'quiqqer/payment-amazon',
                'message.ajax.recurring.cancelBillingAgreement.success',
                [
                    'billingAgreementId' => $billingAgreementId
                ]
            )
        );
    },
    ['billingAgreementId'],
    ['Permission::checkAdminUser', 'quiqqer.payments.amazon.billing_agreements.cancel']
);
