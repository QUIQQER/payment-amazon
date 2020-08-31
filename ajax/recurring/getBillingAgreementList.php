<?php

use QUI\ERP\Payments\Amazon\AmazonPayException;
use QUI\ERP\Payments\Amazon\Recurring\BillingAgreements;
use QUI\Utils\Security\Orthos;
use QUI\Utils\Grid;

/**
 * Get list of Amazon Billing Agreements
 *
 * @param array $searchParams - GRID search params
 * @return array - Billing Agreements list
 * @throws AmazonPayException
 */
QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-amazon_ajax_recurring_getBillingAgreementList',
    function ($searchParams) {
        $searchParams = Orthos::clearArray(json_decode($searchParams, true));

        $Grid = new Grid($searchParams);

        return $Grid->parseResult(
            BillingAgreements::getBillingAgreementList($searchParams),
            BillingAgreements::getBillingAgreementList($searchParams, true)
        );
    },
    ['searchParams'],
    ['Permission::checkAdminUser', 'quiqqer.payments.amazon.billing_agreements.view']
);
