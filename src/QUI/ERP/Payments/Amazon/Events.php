<?php

namespace QUI\ERP\Payments\Amazon;

use QUI;
use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use AmazonPay\IpnHandler;
use QUI\ERP\Order\Handler as OrderHandler;
use QUI\ERP\Payments\Amazon\Payment as AmazonPayment;

/**
 * Class Events
 *
 * Global Event Handler for quiqqer/payment-amazon
 */
class Events
{
    const ACTION_CAPTURE = 'capture';
    const ACTION_REFUND  = 'refund';

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

        if (!($OrderPayment->getPaymentType() instanceof AmazonPayment)) {
            return;
        }

        // determine if payment has to be captured now or later
        $articleType = Provider::getPaymentSetting('article_type');
        $capture     = false;

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

            \QUI\System\Log::writeRecursive("CALLING CAPTURE FROM onQuiqqerOrderSuccessful");
            $Payment->capturePayment($Order);
        } catch (AmazonPayException $Exception) {
            // nothing, capturePayment() marks Order as problematic
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }
    }
}
