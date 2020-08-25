<?php

namespace QUI\ERP\Payments\Amazon;

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
}
