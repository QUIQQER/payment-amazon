<?php

/**
 * This file contains QUI\ERP\Payments\Amazon\Payment
 */

namespace QUI\ERP\Payments\Amazon;

use AmazonPay\Client as AmazonPayClient;
use AmazonPay\ResponseInterface;
use QUI;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Order\Handler as OrderHandler;
use QUI\ERP\Accounting\Payments\Gateway\Gateway;

/**
 * Class Payment
 */
class Payment extends QUI\ERP\Accounting\Payments\Api\AbstractPayment
{
    /**
     * Amazon API Order attributes
     */
    const ATTR_AUTHORIZATION_REFERENCE_IDS = 'amazon-AuthorizationReferenceIds';
    const ATTR_AMAZON_AUTHORIZATION_ID     = 'amazon-AmazonAuthorizationId';
    const ATTR_AMAZON_CAPTURE_ID           = 'amazon-AmazonCaptureId';
    const ATTR_AMAZON_ORDER_REFERENCE_ID   = 'amazon-OrderReferenceId';
    const ATTR_CAPTURE_REFERENCE_IDS       = 'amazon-CaptureReferenceIds';

    /**
     * Setting options
     */
    const SETTING_ARTICLE_TYPE_MIXED    = 'mixed';
    const SETTING_ARTICLE_TYPE_PHYSICAL = 'physical';
    const SETTING_ARTICLE_TYPE_DIGITAL  = 'digital';

    /**
     * Amazon Pay PHP SDK Client
     *
     * @var AmazonPayClient
     */
    protected $AmazonPayClient = null;

    /**
     * Current Order that is being processed
     *
     * @var AbstractOrder
     */
    protected $Order = null;

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
     * Is the payment process successful?
     * This method returns the payment success type
     *
     * @param string $hash - Vorgangsnummer - hash number - procedure number
     * @return bool
     */
    public function isSuccessful($hash)
    {
        \QUI\System\Log::writeRecursive("check sucess $hash");

        try {
            $Order = OrderHandler::getInstance()->getOrderByHash($hash);
        } catch (\Exception $Exception) {
            QUI\System\Log::addError(
                'Amazon Pay :: Cannot check if payment process for Order #' . $hash . ' is successful'
                . ' -> ' . $Exception->getMessage()
            );

            return false;
        }

        $orderReferenceId = $Order->getAttribute(self::ATTR_AMAZON_ORDER_REFERENCE_ID);

        if (empty($orderReferenceId)) {
            return false;
        }

        try {
            self::authorizePayment($orderReferenceId, $Order);
        } catch (AmazonPayException $Exception) {
            return false;
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        // If payment is authorized everyhting is fine
        return true;
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
        $AmazonPay = $this->getAmazonPayClient();
        $Order     = $Gateway->getOrder();

        \QUI\System\Log::writeRecursive($Gateway->getOrder()->getHash());

        $amazonAuthorizationId = $Order->getPaymentDataEntry(self::ATTR_AMAZON_AUTHORIZATION_ID);

        $Response = $AmazonPay->getAuthorizationDetails(array(
            'amazon_authorization_id' => $amazonAuthorizationId
        ));

        $response = $this->getResponseData($Response);

        // check the amount that has already been captured

//        $Gateway->purchase()
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
        $Step->setContent($Engine->fetch(dirname(__FILE__) . '/PaymentDisplay.Header.html'));

        return $Control->create();
    }

    /**
     * Authorize the payment for an Order with Amazon
     *
     * @param string $orderReferenceId
     * @param AbstractOrder $Order
     *
     * @throws AmazonPayException
     */
    public function authorizePayment($orderReferenceId, AbstractOrder $Order)
    {
        $AmazonPay   = $this->getAmazonPayClient();
        $this->Order = $Order;

        $Order->addHistory('Amazon Pay :: Authorize payment');

//        $Order->setPaymentData(self::ATTR_AMAZON_AUTHORIZATION_ID, null);

        $calculations          = $Order->getArticles()->getCalculations();
        $amazonAuthorizationId = $Order->getPaymentDataEntry(self::ATTR_AMAZON_AUTHORIZATION_ID);

        if (!empty($amazonAuthorizationId)) {
            $Order->addHistory('Amazon Pay :: Authorization already exist');

            // check if an Authorization already exists and is in state OPEN
            $Response = $AmazonPay->getAuthorizationDetails(array(
                'amazon_authorization_id' => $amazonAuthorizationId
            ));

            $response = $this->getResponseData($Response);
            $this->checkAuthorizationStatus($response['GetAuthorizationDetailsResult']['AuthorizationDetails']);

            return;
        }

        $Order->addHistory('Amazon Pay :: Authorization does not exist. Requesting new Authorization.');

        $authorizationReferenceId = $this->getNewAuthorizationReferenceId($Order);

        $Response = $AmazonPay->charge(array(
            'amazon_reference_id'        => $orderReferenceId,
            'charge_amount'              => $calculations['sum'],
            'currency_code'              => $Order->getCurrency()->getCode(),
            'authorization_reference_id' => $authorizationReferenceId,
            'charge_order_id'            => $Order->getId(),
            'transaction_timeout'        => 0  // get authorization status synchronously
        ));

        $response = $this->getResponseData($Response);

        if (isset($response['SetOrderReferenceDetailsResult']['OrderReferenceDetails']['Constraints']['Constraint']['ConstraintID'])) {
            $Order->addHistory(
                'Amazon Pay :: An error occurred while requesting the Authorization: "'
                . $response['SetOrderReferenceDetailsResult']['OrderReferenceDetails']['Constraints']['Constraint']['ConstraintID'] . '""'
            );

            $this->throwAmazonPayException(
                $response['SetOrderReferenceDetailsResult']['OrderReferenceDetails']['Constraints']['Constraint']['ConstraintID']
            );
        }

        // save reference ids in $Order
        $authorizationDetails  = $response['AuthorizeResult']['AuthorizationDetails'];
        $amazonAuthorizationId = $authorizationDetails['AmazonAuthorizationId'];

        $this->addAuthorizationReferenceIdToOrder($authorizationReferenceId, $Order);
        $Order->setPaymentData(self::ATTR_AMAZON_AUTHORIZATION_ID, $amazonAuthorizationId);
        $Order->setPaymentData(self::ATTR_AMAZON_ORDER_REFERENCE_ID, $orderReferenceId);

        $Order->update(QUI::getUsers()->getSystemUser());

        $this->checkAuthorizationStatus($authorizationDetails);

        $Order->addHistory('Amazon Pay :: Authorization request successful');

        $Order->setSuccessfulStatus();
    }

    /**
     * Capture the actual payment for an Order
     *
     * @param AbstractOrder $Order
     * @return void
     * @throws AmazonPayException
     */
    public function capturePayment(AbstractOrder $Order)
    {
        $this->Order = $Order;
        $AmazonPay   = $this->getAmazonPayClient();

        $orderReferenceId = $Order->getPaymentDataEntry(self::ATTR_AMAZON_ORDER_REFERENCE_ID);

        if (empty($orderReferenceId)) {
            throw new AmazonPayException(array(
                'quiqqer/payment-amazon',
                'exception.Payment.capture.not_authorized',
                array(
                    'orderHash' => $Order->getHash()
                )
            ));
        }

        try {
            $this->authorizePayment($orderReferenceId, $Order);
        } catch (AmazonPayException $Exception) {
            throw new AmazonPayException(array(
                'quiqqer/payment-amazon',
                'exception.Payment.capture.not_authorized',
                array(
                    'orderHash' => $Order->getHash()
                )
            ));
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return;
        }

        $calculations = $Order->getArticles()->getCalculations();
        $sum          = $calculations['sum'];

        // check if $sum was already fully captured
        $amazonCaptureId = $Order->getPaymentDataEntry(self::ATTR_AMAZON_CAPTURE_ID);

        if (!empty($amazonCaptureId)) {
            $Response = $AmazonPay->getCaptureDetails(array(
                'amazon_capture_id' => $amazonCaptureId
            ));

            \QUI\System\Log::writeRecursive($this->getResponseData($Response));

            return;
        }

        $captureReferenceId = $this->getNewCaptureReferenceId($Order);

        $Response = $AmazonPay->capture(array(
            'amazon_authorization_id' => $Order->getAttribute(self::ATTR_AMAZON_AUTHORIZATION_ID),
            'capture_amount'          => $sum,
            'currency_code'           => $Order->getCurrency()->getCode(),
            'capture_reference_id'    => $captureReferenceId
        ));

        $response = $this->getResponseData($Response);

        // check capture response
        \QUI\System\Log::writeRecursive($response);


//        $Gateway = Gateway::getInstance();
//
//        $Gateway->setOrder($Order);
//
//        $this->executeGatewayPayment($Gateway);
    }

    /**
     * Check AuthorizationDetails of an Amazon Pay Authorization
     *
     * If "State" is "Open" everything is fine and the payment can be captured
     *
     * @param array $authorizationDetails
     * @return void
     * @throws AmazonPayException
     */
    public function checkAuthorizationStatus($authorizationDetails)
    {
        $this->Order->addHistory('Amazon Pay :: Checking Authorization status');

        $status = $authorizationDetails['AuthorizationStatus'];
        $state  = $status['State'];

        switch ($state) {
            case 'Open':
                $this->Order->addHistory(
                    'Amazon Pay :: Authorization is OPEN an can be used for charging'
                );

                return; // everything is fine
                break;

            default:
                $this->Order->addHistory(
                    'Amazon Pay :: Authorization cannot be used because it is in state "' . $state . '".'
                    . ' ReasonCode: "' . $status['ReasonCode'] . '"'
                );

                $this->throwAmazonPayException($status['ReasonCode']);
                break;
        }
    }

    /**
     * Check if the Amazon Pay API response is OK
     *
     * @param ResponseInterface $Response - Amazon Pay API Response
     * @return array
     * @throws AmazonPayException
     */
    protected function getResponseData(ResponseInterface $Response)
    {
        $response = $Response->toArray();

        if (!empty($response['Error']['Code'])) {
            $this->throwAmazonPayException($response['Error']['Code']);
        }

        return $response;
    }

    /**
     * Throw AmazonPayException for specific Amazon API Error
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
            case 'MaxCapturesProcessed':
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
    protected function getNewAuthorizationReferenceId(AbstractOrder $Order)
    {
        $authorizationReferenceIds = $Order->getPaymentDataEntry(self::ATTR_AUTHORIZATION_REFERENCE_IDS);

        if (empty($authorizationReferenceIds)) {
            $no = 1;
        } else {
            $no = count($authorizationReferenceIds) + 1;
        }

        return 'a_' . $Order->getId() . '_' . $no;
    }

    /**
     * Add an AuthorizationReferenceId to an Order
     *
     * @param string $authorizationReferenceId
     * @param AbstractOrder $Order
     * @return void
     */
    protected function addAuthorizationReferenceIdToOrder($authorizationReferenceId, AbstractOrder $Order)
    {
        $authorizationReferenceIds = $Order->getPaymentDataEntry(self::ATTR_AUTHORIZATION_REFERENCE_IDS);

        if (empty($authorizationReferenceIds)) {
            $authorizationReferenceIds = array();
        }

        $authorizationReferenceIds[] = $authorizationReferenceId;

        $Order->setPaymentData(self::ATTR_AUTHORIZATION_REFERENCE_IDS, $authorizationReferenceIds);
        $Order->update(QUI::getUsers()->getSystemUser());
    }

    /**
     * Generate a unique, random CaptureReferenceId to identify
     * captures for an order
     *
     * @param AbstractOrder $Order
     * @return string
     */
    protected function getNewCaptureReferenceId(AbstractOrder $Order)
    {
        $captureReferenceIds = $Order->getPaymentDataEntry(self::ATTR_CAPTURE_REFERENCE_IDS);

        if (empty($captureReferenceIds)) {
            $no = 1;
        } else {
            $no = count($captureReferenceIds) + 1;
        }

        return 'c#' . $Order->getId() . '#' . $no;
    }

    /**
     * Add an CaptureReferenceId to an Order
     *
     * @param string $captureReferenceId
     * @param AbstractOrder $Order
     * @return void
     */
    protected function addCaptureReferenceIdToOrder($captureReferenceId, AbstractOrder $Order)
    {
        $captureReferenceIds = $Order->getPaymentDataEntry(self::ATTR_CAPTURE_REFERENCE_IDS);

        if (empty($captureReferenceIds)) {
            $captureReferenceIds = array();
        }

        $captureReferenceIds[] = $captureReferenceId;

        $Order->setPaymentData(self::ATTR_CAPTURE_REFERENCE_IDS, $captureReferenceIds);
        $Order->update(QUI::getUsers()->getSystemUser());
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
