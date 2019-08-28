<?php

define('QUIQQER_SYSTEM', true);

require_once dirname(dirname(dirname(dirname(__FILE__)))).'/header.php';

use QUI\ERP\Order\Handler;
use QUI\ERP\Payments\Amazon\AmazonPayException;
use QUI\ERP\Payments\Amazon\Payment;
use Symfony\Component\HttpFoundation\RedirectResponse;
use \Symfony\Component\HttpFoundation\Response;
use QUI\Utils\Security\Orthos;
use QUI\ERP\Accounting\Payments\Order\Payment as OrderProcessStepPayments;
use QUI\ERP\Order\Controls\OrderProcess\Finish as OrderProcessStepFinish;

function badRequest()
{
    $Response = QUI::getGlobalResponse();
    $Response->setStatusCode(Response::HTTP_BAD_REQUEST);
    $Response->send();
    exit;
}

if (empty($_REQUEST['hash'])) {
    badRequest();
}

$orderHash = Orthos::clear($_REQUEST['hash']);

try {
    $Order = Handler::getInstance()->getOrderByHash($orderHash);

    $OrderProcess = new QUI\ERP\Order\OrderProcess([
        'orderHash' => $orderHash
    ]);
} catch (\Exception $Exception) {
    QUI\System\Log::writeException($Exception);
    badRequest();
}

if (!empty($_REQUEST['ErrorCode'])) {
    $OrderProcess->addStepMessage(
        Payment::MESSAGE_ID_ERROR_SCA_FAILURE,
        Payment::class,
        OrderProcessStepPayments::class
    );
} elseif (empty($_REQUEST['AuthenticationStatus'])) {
    badRequest();
}

// Default step = payment select
$GoToStep = new OrderProcessStepPayments([
    'Order' => $Order
]);

if ($_REQUEST['AuthenticationStatus'] === 'Success') {
    // If the SCA was successful -> Authorize the Order
    try {
        /** @var Payment $AmazonPayment */
        $AmazonPayment = $Order->getPayment()->getPaymentType();
        $AmazonPayment->authorizePayment($Order);

        // Go to finish step if authorization was successful
        $GoToStep = new OrderProcessStepFinish([
            'Order' => $Order
        ]);

        $OrderProcess->clearStepMessages();
    } catch (AmazonPayException $Exception) {
        $amazonErrorCode = $Exception->getAttribute('amazonErrorCode');

        if (!empty($amazonErrorCode)) {
            $OrderProcess->addStepMessage(
                $amazonErrorCode,
                Payment::class,
                OrderProcessStepPayments::class
            );
        } else {
            $OrderProcess->addStepMessage(
                Payment::MESSAGE_ID_ERROR_INTERNAL,
                Payment::class,
                OrderProcessStepPayments::class
            );
        }
    } catch (\Exception $Exception) {
        $OrderProcess->addStepMessage(
            Payment::MESSAGE_ID_ERROR_INTERNAL,
            Payment::class,
            OrderProcessStepPayments::class
        );
    }
} else {
    $OrderProcess->addStepMessage(
        Payment::MESSAGE_ID_ERROR_INTERNAL,
        Payment::class,
        OrderProcessStepPayments::class
    );
}

$processingUrl = $OrderProcess->getStepUrl($GoToStep->getName());

// Redirect to OrderProcess
$Redirect = new RedirectResponse($processingUrl);
$Redirect->setStatusCode(Response::HTTP_SEE_OTHER);

echo $Redirect->getContent();
$Redirect->send();

exit;
