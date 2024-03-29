<?php

define('QUIQQER_SYSTEM', true);

require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/header.php';

use AmazonPay\IpnHandler;
use QUI\ERP\Accounting\Payments\Transactions\Handler as TransactionsHandler;
use QUI\ERP\Order\Handler as OrderHandler;
use QUI\ERP\Payments\Amazon\Payment as AmazonPayment;
use Symfony\Component\HttpFoundation\Response;

function badRequest()
{
    $Response = QUI::getGlobalResponse();
    $Response->setStatusCode(Response::HTTP_BAD_REQUEST);
    $Response->send();
    exit;
}

$headers = getallheaders();
$body = file_get_contents('php://input');

try {
    $IpnHandler = new IpnHandler($headers, $body);
} catch (\Exception $Exception) {
    // request is not an Amazon IPN request and can be safely ignored here
    exit;
}

$ipnData = $IpnHandler->toArray();

// Handle Refund request
if (!empty($ipnData['RefundDetails']['RefundReferenceId'])) {
    try {
        $AmazonPayment = new AmazonPayment();
        $refundReferenceId = $ipnData['RefundDetails']['RefundReferenceId'];
        $transactionId = $AmazonPayment->rebuildCroppedTransactionId($refundReferenceId);
        $RefundTransaction = TransactionsHandler::getInstance()->get($transactionId);

        $AmazonPayment->finalizeRefund($RefundTransaction, $ipnData['RefundDetails']);
    } catch (\Exception $Exception) {
        QUI\System\Log::writeException($Exception);
        badRequest();
    }
}

// Handle Capture request
if (!empty($ipnData['CaptureDetails']['CaptureReferenceId'])) {
    try {
        // parse Order ID from special reference ID
        $orderIdentifier = explode('_', $ipnData['CaptureDetails']['CaptureReferenceId']);
        $Orders = OrderHandler::getInstance();

        try {
            $Order = $Orders->getOrderById($orderIdentifier[1]);
        } catch (\Exception $Exception) {
            QUI\System\Log::addError(
                'Amazon Pay :: Could not load Order from IPN request. Parsed Order ID: ' . $orderIdentifier[1]
            );

            throw $Exception;
        }

        // Only start capture if it has not already started synchronously
        if (!$Order->getPaymentDataEntry(AmazonPayment::ATTR_AMAZON_CAPTURE_ID)) {
            $Gateway = new QUI\ERP\Accounting\Payments\Gateway\Gateway();

            $Gateway->setOrder($Order);
            $Gateway->executeGatewayPayment();
        }
    } catch (\Exception $Exception) {
        QUI\System\Log::writeException($Exception);
        badRequest();
    }
}

$Response = QUI::getGlobalResponse();
$Response->setStatusCode(Response::HTTP_OK);
$Response->send();

exit;
