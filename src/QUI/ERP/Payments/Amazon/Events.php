<?php

namespace QUI\ERP\Payments\Amazon;

use QUI;
use QUI\ERP\Payments\Amazon\Payment as AmazonPayment;
use QUI\ERP\Payments\Amazon\Recurring\Payment as AmazonPaymentRecurring;

/**
 * Class Events
 *
 * Global Event Handler for quiqqer/payment-amazon
 */
class Events
{
    const ACTION_CAPTURE = 'capture';
    const ACTION_REFUND = 'refund';

    /**
     * quiqqer/order: onQuiqqerOrderSuccessful
     *
     * Check if funds have to be captured as soon as the order is successful
     *
     * @param QUI\ERP\Order\AbstractOrder $Order
     * @return void
     *
     * @throws QUI\ERP\Accounting\Payments\Exception
     */
    public static function onQuiqqerOrderSuccessful(QUI\ERP\Order\AbstractOrder $Order)
    {
        $OrderPayment = $Order->getPayment();

        if (is_null($OrderPayment)) {
            return;
        }

        $OrderPaymentType = $OrderPayment->getPaymentType();

        if (!($OrderPaymentType instanceof AmazonPayment)) {
            return;
        }

        // Recurring payments are handled via cron
        if (($OrderPaymentType instanceof AmazonPaymentRecurring)) {
            return;
        }

        // determine if payment has to be captured now or later
        $articleType = Provider::getPaymentSetting('article_type');
        $capture = false;

        switch ($articleType) {
            case Payment::SETTING_ARTICLE_TYPE_PHYSICAL:
                // later
                break;
            case Payment::SETTING_ARTICLE_TYPE_DIGITAL:
                // now
                $capture = true;
                break;

            default:
                $capture = true;
            // determine by order article type
            // @todo
        }

        if (!$capture) {
            return;
        }

        try {
            $Payment = new Payment();
            $Payment->capturePayment($Order);
        } catch (AmazonPayException $Exception) {
            // nothing, capturePayment() marks Order as problematic
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }
    }
}
