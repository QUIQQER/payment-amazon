<?php

define('QUIQQER_SYSTEM', true);

require_once dirname(dirname(dirname(dirname(__FILE__)))).'/header.php';

use \Symfony\Component\HttpFoundation\Response;
use AmazonPay\IpnHandler;
use QUI\ERP\Accounting\Payments\Transactions\Handler as TransactionsHandler;


$headers = getallheaders();
$body    = file_get_contents('php://input');

try {
    $IpnHandler = new IpnHandler($headers, $body);
} catch (\Exception $Exception) {
    // request is not an Amazon IPN request and can be safely ignored
    exit;
}

$ipnData = $IpnHandler->toArray();

if (empty($ipnData['RefundDetails']['RefundReferenceId'])) {
    // do nothing if the request was not a refund request
    exit;
}

try {
    $AmazonPayment     = new \QUI\ERP\Payments\Amazon\Payment();
    $refundReferenceId = $ipnData['RefundDetails']['RefundReferenceId'];
    $transactionId     = $AmazonPayment->rebuildCroppedTransactionId($refundReferenceId);
    $RefundTransaction = TransactionsHandler::getInstance()->get($transactionId);

    $AmazonPayment->finalizeRefund($RefundTransaction, $ipnData);
} catch (\Exception $Exception) {
    QUI\System\Log::writeException($Exception);

    $Response = QUI::getGlobalResponse();
    $Response->setStatusCode(Response::HTTP_BAD_REQUEST);
    $Response->send();
    exit;
}
