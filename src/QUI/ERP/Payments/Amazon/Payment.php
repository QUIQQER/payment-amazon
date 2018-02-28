<?php

/**
 * This file contains QUI\ERP\Payments\Example\Payment
 */

namespace QUI\ERP\Payments\Amazon;

use AmazonPay\Client as AmazonPayClient;
use QUI;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Order\OrderInterface;

/**
 * Class Payment
 * - This class is your main API point for your payment type
 *
 * @package QUI\ERP\Payments\Example\Example
 */
class Payment extends QUI\ERP\Accounting\Payments\Api\AbstractPayment
{
    /**
     * Amazon Pay PHP SDK Client
     *
     * @var AmazonPayClient
     */
    protected $AmazonPayClient = null;

    /**
     * @return string
     */
    public function getTitle()
    {
        return $this->getLocale()->get('quiqqer/payment-amazon', 'payment.title');
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return $this->getLocale()->get('quiqqer/payment-amazon', 'payment.description');
    }

    /**
     * Is the payment successful?
     * This method returns the payment success type
     *
     * @param string $hash - Vorgangsnummer - hash number - procedure number
     * @return bool
     */
    public function isSuccessful($hash)
    {
        // TODO: Implement isSuccessful() method.
    }

    /**
     * Is the payment a gateway payment?
     *
     * @return bool
     */
    public function isGateway()
    {
        return true;
    }

    /**
     * @return bool
     */
    public function refundSupport()
    {
        return true;
    }

    /**
     * Execute the request from the payment provider
     *
     * @param QUI\ERP\Accounting\Payments\Gateway\Gateway $Gateway
     * @throws QUI\ERP\Accounting\Payments\Exception
     */
    public function executeGatewayPayment(QUI\ERP\Accounting\Payments\Gateway\Gateway $Gateway)
    {

    }

    /**
     * Execute a refund
     *
     * @param QUI\ERP\Accounting\Payments\Transactions\Transaction $Transaction
     */
    public function refund(QUI\ERP\Accounting\Payments\Transactions\Transaction $Transaction)
    {
        // @todo
    }

    /**
     * If the Payment method is a payment gateway, it can return a gateway display
     *
     * @param AbstractOrder $Order
     * @param QUI\ERP\Order\Controls\OrderProcess\Processing $Step
     * @return string
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

        $Step->setContent('');

        return $Control->create();
    }

    /**
     * Start payment process for an Order
     *
     * @param string $accessToken
     * @param string $orderReferenceId
     * @param AbstractOrder $Order
     *
     * @throws AmazonPayException
     */
    public function startPaymentProcess($accessToken, $orderReferenceId, AbstractOrder $Order)
    {
        $AmazonPay = $this->getAmazonPayClient();

        // Step 1 - Authorize payment
        $calculations = $Order->getArticles()->getCalculations();

        $Response = $AmazonPay->charge(array(
            'amazon_reference_id'        => $orderReferenceId,
            'charge_amount'              => $calculations['sum'],
            'currency_code'              => $Order->getCurrency()->getCode(),
            'authorization_reference_id' => random_int(100, 1000),//$this->getAuthorizationReferenceId($Order),
            'charge_order_id'            => $Order->getHash()
        ));

        $response = $Response->toArray();

        if (!empty($response['Error'])) {
            $Order->addHistory(
                'AmazonPay :: Authorize Payment'
            );

            $this->throwAmazonPayException($response['Error']);
        }

        if (isset($response['SetOrderReferenceDetailsResult']['OrderReferenceDetails']['Constraints']['Constraint'])) {
            $this->throwAmazonPayException(
                $response['SetOrderReferenceDetailsResult']['OrderReferenceDetails']['Constraints']['Constraint']['ConstraintID']
            );
        }
    }

    /**
     * Get error msg for specific Amazon API Error
     *
     * @param string $errorCode
     * @return string
     *
     * @throws AmazonPayException
     */
    protected function throwAmazonPayException($errorCode)
    {
        $L              = $this->getLocale();
        $lg             = 'quiqqer/payment-amazon';
        $msg            = $L->get($lg, 'payment.error_msg.general_error');
        $reRenderWallet = false;

        switch ($errorCode) {
            case 'InvalidPaymentMethod':
            case 'TransactionTimedOut':
            case 'AmazonRejected':
                $msg = $L->get($lg, 'payment.error_msg.' . $errorCode);
                break;

            default:
        }

        $Exception = new AmazonPayException($msg);
        $Exception->setAttribute('reRenderWallet', $reRenderWallet);

        throw $Exception;
    }

    /**
     * Generate a unique, random Authorization Reference ID to identify
     * authorization transactions for an order
     *
     * @param AbstractOrder $Order
     * @return string
     */
    protected function getAuthorizationReferenceId(AbstractOrder $Order)
    {
        return mb_substr('amazon-' . $Order->getHash(), 0, 32);
    }

    /**
     * Get Amazon Pay Client for current payment process
     *
     * @return AmazonPayClient
     */
    protected function getAmazonPayClient()
    {
        if (!is_null($this->AmazonPayClient)) {
            return $this->AmazonPayClient;
        }

        $this->AmazonPayClient = new AmazonPayClient(array(
            'merchant_id' => Provider::getApiSetting('merchant_id'),
            'access_key'  => Provider::getApiSetting('access_key'),
            'secret_key'  => Provider::getApiSetting('secret_key'),
            'client_id'   => Provider::getApiSetting('client_id'),
            'sandbox'     => boolval(Provider::getApiSetting('sandbox')),
            'region'      => Provider::getApiSetting('region')
        ));

        return $this->AmazonPayClient;
    }
}
