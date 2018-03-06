<?php

use QUI\ERP\Payments\Amazon\AmazonPayException;
use QUI\Utils\Security\Orthos;

/**
 * Log errors that occurred in the frontend
 *
 * List of possible frontend errors:
 * https://pay.amazon.com/de/developer/documentation/lpwa/201954960 [02.03.2018]
 *
 * @param string $errorCode
 * @param string $errorMsg
 * @throws AmazonPayException
 */
QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-amazon_ajax_logFrontendError',
    function ($errorCode, $errorMsg) {
        $errorCode = Orthos::clear($errorCode);
        $errorMsg  = Orthos::clear($errorMsg);

        switch ($errorCode) {
            // error code whitelist to avoid spam
            case 'AddressNotModifiable':
            case 'BuyerNotAssociated':
            case 'BuyerSessionExpired':
            case 'InvalidAccountStatus':
            case 'InvalidOrderReferenceId':
            case 'InvalidParameterValue':
            case 'InvalidSellerId':
            case 'MissingParameter':
            case 'PaymentMethodNotModifiable':
            case 'ReleaseEnvironmentMismatch':
            case 'StaleOrderReference':
            case 'UnknownError':
                QUI\System\Log::addError(
                    'Amazon Pay - Frontend error :: There was a problem with your Amazon Pay Frontend.'
                    . ' The payment process could not be used correctly. Please check your Amazon Pay'
                    . ' settings and/or test your environment in sandbox mode.'
                    . ' -> Error Code: "' . $errorCode . '" | Error Message: "' . $errorMsg . '"'
                );
                break;
        }
    },
    array('errorCode', 'errorMsg')
);
