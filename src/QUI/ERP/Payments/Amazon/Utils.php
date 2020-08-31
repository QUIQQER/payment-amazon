<?php

namespace QUI\ERP\Payments\Amazon;

use AmazonPay\ResponseInterface;
use QUI;
use QUI\ERP\Accounting\Payments\Payments;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Accounting\Invoice\Invoice;

/**
 * Class Utils
 *
 * Utility methods for quiqqer/payment-amazon
 */
class Utils
{
    /**
     * Get base URL (with host) for current Project
     *
     * @return string
     */
    public static function getProjectUrl()
    {
        try {
            $url = QUI::getRewrite()->getProject()->get(1)->getUrlRewrittenWithHost();
            $url = \str_replace(['http://', 'https://'], '', $url); // remove protocol

            return rtrim($url, '/');
        } catch (\Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);
            return '';
        }
    }

    /**
     * Save Order with SystemUser
     *
     * @param AbstractOrder $Order
     * @return void
     */
    public static function saveOrder(AbstractOrder $Order)
    {
        $Order->update(QUI::getUsers()->getSystemUser());
    }

    /**
     * Get translated history text
     *
     * @param string $context
     * @param array $data (optional) - Additional data for translation
     * @return string
     */
    public static function getHistoryText(string $context, $data = [])
    {
        return QUI::getLocale()->get('quiqqer/payment-amazon', 'history.'.$context, $data);
    }

    /**
     * Get confirmation flow success url
     *
     * @param AbstractOrder $Order
     * @return string
     */
    public static function getSuccessUrl(AbstractOrder $Order)
    {
        return Payments::getInstance()->getHost().
               URL_OPT_DIR.
               'quiqqer/payment-amazon/bin/confirmation.php?hash='.$Order->getHash();
    }

    /**
     * Get confirmation flow error url
     *
     * @param AbstractOrder $Order
     * @return string
     */
    public static function getFailureUrl(AbstractOrder $Order)
    {
        return Payments::getInstance()->getHost().
               URL_OPT_DIR.
               'quiqqer/payment-amazon/bin/confirmation.php?hash='.$Order->getHash().'&error=1';
    }

    /**
     * Get the total price of an Order formatted for Amazon Pay API usage.
     *
     * @param AbstractOrder $Order
     * @return string
     *
     * @throws QUI\Exception
     */
    public static function getFormattedPriceByOrder(AbstractOrder $Order)
    {
        return (string)$Order->getPriceCalculation()->getSum()->precision(2)->get();
    }

    /**
     * Get the total price of an Invoice formatted for Amazon Pay API usage.
     *
     * @param Invoice $Invoice
     * @return string
     */
    public static function getFormattedPriceByInvoice(Invoice $Invoice)
    {
        $Invoice->calculatePayments();
        return (string)(float)$Invoice->getAttribute('toPay');
    }

    /**
     * Format a string for Amazon Api usage.
     *
     * The Amazon docs usually recommend the following characters: A-Z a-z 0-9 - _
     *
     * @param string $str
     * @param int $maxLength (optional)
     * @return string
     */
    public static function formatApiString(string $str, int $maxLength = null)
    {
        $str = \preg_replace('#[^A-Za-z0-9\-_]#i', '', $str);

        if (!empty($maxLength)) {
            $str = \mb_substr($str, 0, $maxLength);
        }

        return $str;
    }

    /**
     * Check if the Amazon Pay API response is OK and return response data as array
     *
     * @param ResponseInterface $Response - Amazon Pay API Response
     * @return array
     * @throws AmazonPayException
     */
    public static function getResponseData(ResponseInterface $Response)
    {
        $response = $Response->toArray();

        if (!empty($response['Error']['Code'])) {
            self::throwAmazonPayException(
                $response['Error']['Code'],
                [
                    'amazonApiErrorCode' => $response['Error']['Code'],
                    'amazonApiErrorMsg'  => $response['Error']['Message']
                ]
            );
        }

        return $response;
    }

    /**
     * Throw AmazonPayException for specific Amazon API Error
     *
     * @param string $errorCode
     * @param array $exceptionAttributes (optional) - Additional Exception attributes that may be relevant for the Frontend
     * @return string
     *
     * @throws AmazonPayException
     */
    public static function throwAmazonPayException($errorCode, $exceptionAttributes = [])
    {
        $L   = QUI::getLocale();
        $lg  = 'quiqqer/payment-amazon';
        $msg = $L->get($lg, 'payment.error_msg.general_error');

        switch ($errorCode) {
            case 'InvalidPaymentMethod':
            case 'PaymentMethodNotAllowed':
            case 'TransactionTimedOut':
            case 'AmazonRejected':
            case 'ProcessingFailure':
            case 'MaxCapturesProcessed':
                $msg                                    = $L->get($lg, 'payment.error_msg.'.$errorCode);
                $exceptionAttributes['amazonErrorCode'] = $errorCode;
                break;
        }

        $Exception = new AmazonPayException($msg);
        $Exception->setAttributes($exceptionAttributes);

        QUI\System\Log::writeException($Exception, QUI\System\Log::LEVEL_DEBUG, $exceptionAttributes);

        throw $Exception;
    }
}
