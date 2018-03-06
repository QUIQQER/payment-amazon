<?php

/**
 * This file contains QUI\ERP\Payments\Example\PaymentDisplay
 */

namespace QUI\ERP\Payments\Amazon;

use QUI;

/**
 * Class PaymentDisplay
 *
 * Display Amazon Pay payment process
 */
class PaymentDisplay extends QUI\Control
{
    /**
     * Constructor
     *
     * @param array $attributes
     */
    public function __construct(array $attributes = array())
    {
        parent::__construct($attributes);

        $this->addCSSFile(dirname(__FILE__) . '/PaymentDisplay.css');

        $this->setJavaScriptControl('package/quiqqer/payment-amazon/bin/controls/PaymentDisplay');
        $this->setJavaScriptControlOption('sandbox', boolval(Provider::getApiSetting('sandbox')));
        $this->setJavaScriptControlOption('sellerid', Provider::getApiSetting('merchant_id'));
        $this->setJavaScriptControlOption('clientid', Provider::getApiSetting('client_id'));
    }

    /**
     * Return the body of the control
     * Here you can integrate the payment form, or forwarding functionality to the gateway
     *
     * @return string
     * @throws QUI\Exception
     */
    public function getBody()
    {
        $Engine = QUI::getTemplateManager()->getEngine();

        /* @var $Order QUI\ERP\Order\OrderInProcess */
        $Order            = $this->getAttribute('Order');
        $PriceCalculation = $Order->getPriceCalculation();

        $Engine->assign(array(
            'btn_size'      => Provider::getWidgetsSetting('btn_size'),
            'btn_color'     => Provider::getWidgetsSetting('btn_color'),
            'display_price' => $PriceCalculation->getSum()->formatted(),
            'apiSetUp'      => $this->isApiSetUp()
        ));

        $this->setJavaScriptControlOption('orderhash', $Order->getHash());

        // Check if an Amazon Pay authorization already exists (i.e. Order is successful / can be processed)
        $this->setJavaScriptControlOption('successful', $Order->isSuccessful());

        return $Engine->fetch(dirname(__FILE__) . '/PaymentDisplay.html');
    }

    /**
     * Check if the Amazon Pay API settings are correct
     *
     * @return bool
     * @throws QUI\Exception
     */
    protected function isApiSetUp()
    {
        $Conf        = QUI::getPackage('quiqqer/payment-amazon')->getConfig();
        $apiSettings = $Conf->getSection('api');

        foreach ($apiSettings as $k => $v) {
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
