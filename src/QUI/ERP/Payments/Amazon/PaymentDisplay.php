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
    public function __construct(array $attributes = [])
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
    public function getBody(): string
    {
        $Engine = QUI::getTemplateManager()->getEngine();

        /* @var $Order QUI\ERP\Order\OrderInProcess */
        $Order = $this->getAttribute('Order');
        $PriceCalculation = $Order->getPriceCalculation();

        $Engine->assign([
            'btn_size' => Provider::getWidgetsSetting('btn_size'),
            'btn_color' => Provider::getWidgetsSetting('btn_color'),
            'display_price' => $PriceCalculation->getSum()->formatted(),
            'apiSetUp' => Provider::isApiSetUp(),
            'currency_code' => $Order->getCurrency()->getCode()
        ]);

        $this->setJavaScriptControlOption('orderhash', $Order->getUUID());

        // Check if an Amazon Pay authorization already exists (i.e. Order is successful / can be processed)
        $this->setJavaScriptControlOption('successful', $Order->isSuccessful());

        return $Engine->fetch(dirname(__FILE__) . '/PaymentDisplay.html');
    }
}
