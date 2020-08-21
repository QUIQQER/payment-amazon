<?php

namespace QUI\ERP\Payments\Amazon\Recurring;

use QUI;
use QUI\ERP\Payments\Amazon\Provider;

/**
 * Class PaymentDisplay
 *
 * Display PayPal Billing payment process
 */
class PaymentDisplay extends QUI\Control
{
    /**
     * Constructor
     *
     * @param array $attributes
     * @throws QUI\ERP\Order\ProcessingException
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setJavaScriptControl('package/quiqqer/payment-amazon/bin/controls/recurring/PaymentDisplay');

        $this->addCSSFile(dirname(__FILE__).'/PaymentDisplay.css');

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

        $Engine->assign([
            'btn_size'      => Provider::getWidgetsSetting('btn_size'),
            'btn_color'     => Provider::getWidgetsSetting('btn_color'),
            'display_price' => $PriceCalculation->getSum()->formatted(),
            'apiSetUp'      => Provider::isApiSetUp(),
            'currency_code' => $Order->getCurrency()->getCode()
        ]);

        $this->setJavaScriptControlOption('orderhash', $Order->getHash());

        // Check if an Amazon Pay authorization already exists (i.e. Order is successful / can be processed)
        $this->setJavaScriptControlOption('successful', $Order->isSuccessful());

        return $Engine->fetch(dirname(__FILE__).'/PaymentDisplay.html');
    }
}
