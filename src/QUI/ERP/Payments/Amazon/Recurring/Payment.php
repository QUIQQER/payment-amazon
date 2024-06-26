<?php

namespace QUI\ERP\Payments\Amazon\Recurring;

use Exception;
use QUI;
use QUI\ERP\Accounting\Invoice\Invoice;
use QUI\ERP\Accounting\Payments\Types\RecurringPaymentInterface;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\Amazon\Payment as BasePayment;

use function array_column;

class Payment extends BasePayment implements RecurringPaymentInterface
{
    /**
     * Amazon API Order attributes for recurring payments
     */
    const ATTR_AMAZON_BILLING_AGREEMENT_ID = 'amazon-AmazonBillingAgreementId';
    const ATTR_AMAZON_BILLING_AGREEMENT_CONFIRMED = 'amazon-AmazonBillingAgreementConfirmed';
    const ATTR_AMAZON_BILLING_AGREEMENT_VALIDATED = 'amazon-AmazonBillingAgreementValidated';

    /**
     * @return string
     */
    public function getTitle(): string
    {
        return $this->getLocale()->get('quiqqer/payment-amazon', 'payment.recurring.title');
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return $this->getLocale()->get('quiqqer/payment-amazon', 'payment.recurring.description');
    }

    /**
     * If the Payment method is a payment gateway, it can return a gateway display
     *
     * @param AbstractOrder $Order
     * @param ?QUI\ERP\Order\Controls\AbstractOrderingStep $Step
     * @return string
     *
     * @throws QUI\Exception|Exception
     */
    public function getGatewayDisplay(
        AbstractOrder $Order,
        ?QUI\ERP\Order\Controls\AbstractOrderingStep $Step = null
    ): string {
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
        $Step->setContent($Engine->fetch(dirname(__FILE__, 2) . '/PaymentDisplay.Header.html'));

        return $Control->create();
    }

    /**
     * Does the payment ONLY support recurring payments (e.g. for subscriptions)?
     *
     * @return bool
     */
    public function supportsRecurringPaymentsOnly(): bool
    {
        return true;
    }

    /**
     * Create a Subscription from a (temporary) Order
     *
     * @param AbstractOrder $Order
     * @return string|null
     */
    public function createSubscription(AbstractOrder $Order): ?string
    {
        // There is no need to create a BillingAgreement here since this
        // is done in the frontend via the Amazon BillingAgreement consent widget
        return null;
    }

    /**
     * Capture subscription amount based on an Invoice
     *
     * @param Invoice $Invoice
     * @return void
     */
    public function captureSubscription(Invoice $Invoice): void
    {
        try {
            BillingAgreements::billBillingAgreementBalance($Invoice);
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }
    }

    /**
     * Cancel a Subscription
     *
     * @param int|string $subscriptionId
     * @param string $reason (optional) - The reason why the subscription is cancelled
     * @return void
     */
    public function cancelSubscription(int|string $subscriptionId, string $reason = ''): void
    {
        BillingAgreements::cancelBillingAgreement($subscriptionId, $reason);
    }

    /**
     * Suspend a Subscription
     *
     * This *temporarily* suspends the automated collection of payments until explicitly resumed.
     *
     * @param int|string $subscriptionId
     * @param string|null $note (optional) - Suspension note
     * @return void
     * @throws QUI\Database\Exception
     */
    public function suspendSubscription(int|string $subscriptionId, string $note = null): void
    {
        BillingAgreements::suspendSubscription($subscriptionId);
    }

    /**
     * Resume a suspended Subscription
     *
     * This resumes automated collection of payments of a previously supsendes Subscription.
     *
     * @param int|string $subscriptionId
     * @param string|null $note (optional) - Resume note
     * @return void
     * @throws QUI\Database\Exception
     */
    public function resumeSubscription(int|string $subscriptionId, string $note = null): void
    {
        BillingAgreements::resumeSubscription($subscriptionId);
    }

    /**
     * Checks if a subscription is currently suspended
     *
     * @param int|string $subscriptionId
     * @return bool
     * @throws QUI\Database\Exception
     */
    public function isSuspended(int|string $subscriptionId): bool
    {
        return BillingAgreements::isSuspended($subscriptionId);
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
    public function setSubscriptionAsInactive($subscriptionId): void
    {
        try {
            QUI::getDataBase()->update(
                BillingAgreements::getBillingAgreementsTable(),
                ['active' => 0],
                ['amazon_agreement_id' => $subscriptionId]
            );
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }
    }

    /**
     * Can the Subscription of this payment method be edited
     * regarding essential data like invoice frequency, amount etc.?
     *
     * @return bool
     */
    public function isSubscriptionEditable(): bool
    {
        return true;
    }

    /**
     * Check if a Subscription is associated with an order and
     * return its ID (= identification at the payment method side; e.g. PayPal)
     *
     * @param AbstractOrder $Order
     * @return int|string|false - ID or false of no ID associated
     */
    public function getSubscriptionIdByOrder(AbstractOrder $Order): bool|int|string
    {
        $billingAgreementId = $Order->getPaymentDataEntry(self::ATTR_AMAZON_BILLING_AGREEMENT_ID);
        return $billingAgreementId ?: false;
    }

    /**
     * Checks if the subscription is active at the payment provider side
     *
     * @param int|string $subscriptionId
     * @return bool
     */
    public function isSubscriptionActiveAtPaymentProvider(int|string $subscriptionId): bool
    {
        return BillingAgreements::isBillingAgreementActiveAtAmazon($subscriptionId);
    }

    /**
     * Checks if the subscription is active at QUIQQER
     *
     * @param int|string $subscriptionId - Payment provider subscription ID
     * @return bool
     */
    public function isSubscriptionActiveAtQuiqqer(int|string $subscriptionId): bool
    {
        try {
            $result = QUI::getDataBase()->fetch([
                'select' => ['active'],
                'from' => BillingAgreements::getBillingAgreementsTable(),
                'where' => [
                    'amazon_agreement_id' => $subscriptionId
                ]
            ]);

            if (empty($result)) {
                return false;
            }

            return !empty($result[0]['active']);
        } catch (Exception) {
            return true;
        }
    }

    /**
     * Get IDs of all subscriptions
     *
     * @param bool $includeInactive (optional) - Include inactive subscriptions [default: false]
     * @return string[]
     */
    public function getSubscriptionIds(bool $includeInactive = false): array
    {
        try {
            $where = [
                'active' => 1
            ];

            if ($includeInactive) {
                unset($where['active']);
            }

            $result = QUI::getDataBase()->fetch([
                'select' => ['amazon_agreement_id'],
                'from' => BillingAgreements::getBillingAgreementsTable(),
                'where' => $where
            ]);

            return array_column($result, 'amazon_agreement_id');
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return [];
        }
    }

    /**
     * Get global processing ID of a subscription
     *
     * @param int|string $subscriptionId
     * @return string|false
     */
    public function getSubscriptionGlobalProcessingId(int|string $subscriptionId): bool|string
    {
        try {
            $result = QUI::getDataBase()->fetch([
                'select' => ['global_process_id'],
                'from' => BillingAgreements::getBillingAgreementsTable(),
                'where' => [
                    'amazon_agreement_id' => $subscriptionId
                ]
            ]);

            if (empty($result)) {
                return false;
            }

            return $result[0]['global_process_id'];
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }
    }

    /**
     * Is the payment process successful?
     * This method returns the payment success type
     *
     * @param string $hash - Vorgangsnummer - hash number - procedure number
     * @return bool
     */
    public function isSuccessful(string $hash): bool
    {
        try {
            $Order = QUI\ERP\Order\Handler::getInstance()->getOrderByHash($hash);
        } catch (Exception $Exception) {
            QUI\System\Log::addError(
                'Amazon Pay :: Cannot check if payment process for Order #' . $hash . ' is successful'
                . ' -> ' . $Exception->getMessage()
            );

            return false;
        }

        return $Order->getPaymentDataEntry(self::ATTR_AMAZON_BILLING_AGREEMENT_VALIDATED);
    }
}
