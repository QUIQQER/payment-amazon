<?php

namespace QUI\ERP\Payments\Amazon\Recurring;

use QUI;
use QUI\ERP\Accounting\Invoice\Invoice;
use QUI\ERP\Accounting\Payments\Types\RecurringPaymentInterface;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\Amazon\Payment as BasePayment;

class Payment extends BasePayment implements RecurringPaymentInterface
{
    /**
     * Amazon API Order attributes for recurring payments
     */
    const ATTR_AMAZON_BILLING_AGREEMENT_ID        = 'amazon-AmazonBillingAgreementId';
    const ATTR_AMAZON_BILLING_AGREEMENT_CONFIRMED = 'amazon-AmazonBillingAgreementConfirmed';
    const ATTR_AMAZON_BILLING_AGREEMENT_VALIDATED = 'amazon-AmazonBillingAgreementValidated';

    /**
     * @return string
     */
    public function getTitle()
    {
        return $this->getLocale()->get('quiqqer/payment-amazon', 'payment.recurring.title');
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return $this->getLocale()->get('quiqqer/payment-amazon', 'payment.recurring.description');
    }

    /**
     * If the Payment method is a payment gateway, it can return a gateway display
     *
     * @param AbstractOrder $Order
     * @param QUI\ERP\Order\Controls\OrderProcess\Processing $Step
     * @return string
     *
     * @throws QUI\Exception
     */
    public function getGatewayDisplay(AbstractOrder $Order, $Step = null)
    {
        $Control = new PaymentDisplay();
        $Control->setAttribute('Order', $Order);
        $Control->setAttribute('Payment', $this);

        $Step->setTitle(
            QUI::getLocale()->get(
                'quiqqer/payment-amazon',
                'payment.step.title'
            )
        );

        $Engine = QUI::getTemplateManager()->getEngine();
        $Step->setContent($Engine->fetch(dirname(__FILE__, 2).'/PaymentDisplay.Header.html'));

        return $Control->create();
    }

    /**
     * Does the payment ONLY support recurring payments (e.g. for subscriptions)?
     *
     * @return bool
     */
    public function supportsRecurringPaymentsOnly()
    {
        return true;
    }

    /**
     * Create a Scubscription from a (temporary) Order
     *
     * @param AbstractOrder $Order
     * @return void
     */
    public function createSubscription(AbstractOrder $Order)
    {
        // There is no need to create a BillingAgreement here since this
        // is done in the frontend via the Amazon BillingAgreement consent widget
    }

    /**
     * Capture subscription amount based on an Invoice
     *
     * @param Invoice $Invoice
     * @return void
     */
    public function captureSubscription(Invoice $Invoice)
    {
        // TODO: Implement captureSubscription() method.
    }

    /**
     * Cancel a Subscription
     *
     * @param int|string $subscriptionId
     * @param string $reason (optional) - The reason why the subscription is cancelled
     * @return void
     */
    public function cancelSubscription($subscriptionId, $reason = '')
    {
        // TODO: Implement cancelSubscription() method.
    }

    /**
     * Sets a subscription as inactive (on the side of this QUIQQER system only!)
     *
     * IMPORTANT: This does NOT mean that the corresponding subscription at the payment provider
     * side is cancelled. If you want to do this please use cancelSubscription() !
     *
     * @param $subscriptionId
     * @return void
     */
    public function setSubscriptionAsInactive($subscriptionId)
    {
        // TODO: Implement setSubscriptionAsInactive() method.
    }

    /**
     * Can the Subscription of this payment method be edited
     * regarding essential data like invoice frequency, amount etc.?
     *
     * @return bool
     */
    public function isSubscriptionEditable()
    {
        // TODO: Implement isSubscriptionEditable() method.
    }

    /**
     * Check if a Subscription is associated with an order and
     * return its ID (= identification at the payment method side; e.g. PayPal)
     *
     * @param AbstractOrder $Order
     * @return int|string|false - ID or false of no ID associated
     */
    public function getSubscriptionIdByOrder(AbstractOrder $Order)
    {
        // TODO: Implement getSubscriptionIdByOrder() method.
    }

    /**
     * Checks if the subscription is active at the payment provider side
     *
     * @param string|int $subscriptionId
     * @return bool
     */
    public function isSubscriptionActiveAtPaymentProvider($subscriptionId)
    {
        // TODO: Implement isSubscriptionActiveAtPaymentProvider() method.
    }

    /**
     * Checks if the subscription is active at QUIQQER
     *
     * @param string|int $subscriptionId - Payment provider subscription ID
     * @return bool
     */
    public function isSubscriptionActiveAtQuiqqer($subscriptionId)
    {
        // TODO: Implement isSubscriptionActiveAtQuiqqer() method.
    }

    /**
     * Get IDs of all subscriptions
     *
     * @param bool $includeInactive (optional) - Include inactive subscriptions [default: false]
     * @return int[]
     */
    public function getSubscriptionIds($includeInactive = false)
    {
        // TODO: Implement getSubscriptionIds() method.
    }

    /**
     * Get global processing ID of a subscription
     *
     * @param string|int $subscriptionId
     * @return string|false
     */
    public function getSubscriptionGlobalProcessingId($subscriptionId)
    {
        // TODO: Implement getSubscriptionGlobalProcessingId() method.
    }
}
