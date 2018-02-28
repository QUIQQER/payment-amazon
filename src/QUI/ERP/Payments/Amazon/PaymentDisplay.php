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
     */
    public function getBody()
    {
        $Engine = QUI::getTemplateManager()->getEngine();

        /* @var $Order QUI\ERP\Order\OrderInProcess */
        $Order = $this->getAttribute('Order');

        /* @var $Payment QUI\ERP\Accounting\Payments\Api\AbstractPayment */
        $Payment = $this->getAttribute('Payment');

        $Gateway = QUI\ERP\Accounting\Payments\Gateway\Gateway::getInstance();
        $Gateway->setOrder($Order);

        $Engine->assign(array(
            'Order'      => $Order,
            'Payment'    => $Payment,
            'gatewayUrl' => $Gateway->getGatewayUrl(),
            'cancelUrl'  => $Gateway->getCancelUrl(),
            'successUrl' => $Gateway->getSuccessUrl(),
            'orderUrl'   => $Gateway->getOrderUrl()
        ));

        $this->setJavaScriptControlOption('orderhash', $Order->getHash());

        return $Engine->fetch(dirname(__FILE__) . '/PaymentDisplay.html');
    }
}
