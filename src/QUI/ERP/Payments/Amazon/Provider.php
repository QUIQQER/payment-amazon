<?php

/**
 * This file contains QUI\ERP\Payments\Amazon\Provider
 */

namespace QUI\ERP\Payments\Amazon;

use QUI;
use QUI\ERP\Accounting\Payments\Api\AbstractPaymentProvider;

/**
 * Class Provider
 *
 * PaymentProvider class for Amazon Pay
 */
class Provider extends AbstractPaymentProvider
{
    /**
     * @return array
     */
    public function getPaymentTypes()
    {
        return [
            Payment::class
        ];
    }

    /**
     * Get API setting
     *
     * @param string $setting - Setting name
     * @return string|number|false
     */
    public static function getApiSetting($setting)
    {
        try {
            $Conf = QUI::getPackage('quiqqer/payment-amazon')->getConfig();
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        return $Conf->get('api', $setting);
    }

    /**
     * Get Payment setting
     *
     * @param string $setting - Setting name
     * @return string|number|false
     */
    public static function getPaymentSetting($setting)
    {
        try {
            $Conf = QUI::getPackage('quiqqer/payment-amazon')->getConfig();
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        return $Conf->get('payment', $setting);
    }

    /**
     * Get Widgets setting
     *
     * @param string $setting - Setting name
     * @return string|number|false
     */
    public static function getWidgetsSetting($setting)
    {
        try {
            $Conf = QUI::getPackage('quiqqer/payment-amazon')->getConfig();
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        return $Conf->get('widgets', $setting);
    }

    /**
     * Check if IPN handling is activated in the module settings
     *
     * @return bool
     */
    public static function isIpnHandlingActivated()
    {
        return boolval(self::getApiSetting('use_ipn_handler'));
    }

    /**
     * Check if the Amazon Pay API settings are correct
     *
     * @return bool
     * @throws QUI\Exception
     */
    public static function isApiSetUp()
    {
        $Conf        = QUI::getPackage('quiqqer/payment-amazon')->getConfig();
        $apiSettings = $Conf->getSection('api');

        foreach ($apiSettings as $k => $v) {
            switch ($k) {
                case 'sandbox':
                case 'use_ipn_handler':
                    continue 2;
                    break;
            }

            if (empty($v)) {
                QUI\System\Log::addError(
                    'Your Amazon Pay API credentials seem to be (partially) missing.'
                    . ' Amazon Pay CAN NOT be used at the moment. Please enter all your'
                    . ' API credentials. See https://dev.quiqqer.com/quiqqer/payment-amazon/wikis/api-configuration'
                    . ' for further instructions.'
                );

                return false;
            }
        }

        return true;
    }
}
