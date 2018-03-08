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
    /**
     * quiqqer/payments: onPaymentsGatewayReadRequest
     *
     * Read request to the central payment gateway and check
     * if it is an Amazon Pay request
     *
     * @param Gateway $Gateway
     * @return void
     */
    public static function onPaymentsGatewayReadRequest(Gateway $Gateway)
    {
        $headers = getallheaders();
        $body    = file_get_contents('php://input');

        try {
            $IpnHandler = new IpnHandler($headers, $body);
        } catch (\Exception $Exception) {
            // request is not an Amazon IPN request and can be safely ignored
            return;
        }

        $ipnData         = $IpnHandler->toArray();
        $orderIdentifier = false;

        if (!empty($ipnData['AuthorizationDetails']['AuthorizationReferenceId'])) {
            // do not work with asynchronous Authorize requests
            return;
        }

        if (!empty($ipnData['CaptureDetails']['CaptureReferenceId'])) {
            $orderIdentifier = $ipnData['CaptureDetails']['CaptureReferenceId'];
        }

        if (!$orderIdentifier) {
            QUI\System\Log::addDebug(
                'Amazon Pay :: Could not parse AuthorizationReferenceId or CaptureReferenceId from IPN request.'
                . ' IPN request data: ' . $IpnHandler->toJson()
            );

            return;
        }

        // parse Order ID from AuthorizationReferenceId
        $orderIdentifier = explode('_', $orderIdentifier);
        $Orders          = OrderHandler::getInstance();

        try {
            $Order = $Orders->getOrderById($orderIdentifier[1]);
        } catch (\Exception $Exception) {
            QUI\System\Log::addError(
                'Amazon Pay :: Could not load Order from IPN request. Parsed Order ID: ' . $orderIdentifier[1]
            );

            return;
        }

        $Gateway->setOrder($Order);
        $Gateway->enableGatewayPayment();

        // now the Gateway can call executeGatewayPayment() of the
        // payment method that is assigned to the Order
    }

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
            $Payment->capturePayment($Order);
        } catch (AmazonPayException $Exception) {
            // nothing, capturePayment() marks Order as problematic
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }
    }
}
