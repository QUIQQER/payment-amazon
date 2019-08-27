<?php

namespace QUI\ERP\Payments\Amazon;

use AmazonPay\Client as AmazonPayClient;
use AmazonPay\ResponseInterface;
use QUI;
use QUI\ERP\Accounting\Payments\Payments;
use QUI\ERP\Accounting\Payments\Transactions\Transaction;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Order\Handler as OrderHandler;
use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use QUI\ERP\Accounting\Payments\Transactions\Factory as TransactionFactory;
use QUI\ERP\Accounting\Payments\Transactions\Handler as TransactionHandler;

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
    const ATTR_AMAZON_REFUND_ID            = 'amazon-RefundId';
    const ATTR_CAPTURE_REFERENCE_IDS       = 'amazon-CaptureReferenceIds';
    const ATTR_ORDER_REFUND_DETAILS        = 'amazon-RefundDetails';
    const ATTR_ORDER_AUTHORIZED            = 'amazon-OrderAuthorized';
    const ATTR_ORDER_CAPTURED              = 'amazon-OrderCaptures';
    const ATTR_ORDER_REFERENCE_SET         = 'amazon-OrderReferenceSet';
    const ATTR_RECONFIRM_ORDER             = 'amazon-ReconfirmOrder';

    /**
     * Setting options
     */
    const SETTING_ARTICLE_TYPE_MIXED    = 'mixed';
    const SETTING_ARTICLE_TYPE_PHYSICAL = 'physical';
    const SETTING_ARTICLE_TYPE_DIGITAL  = 'digital';

    /**
     * Error codes
     */
    const AMAZON_ERROR_REFUND_ORDER_NOT_CAPTURED = 'refund_order_not_captured';

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
     * Return the payment icon (the URL path)
     * Can be overwritten
     *
     * @return string
     */
    public function getIcon()
    {
        return Payments::getInstance()->getHost().
               URL_OPT_DIR.
               'quiqqer/payment-amazon/bin/images/Payment.jpg';
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
        try {
            $Order = OrderHandler::getInstance()->getOrderByHash($hash);
        } catch (\Exception $Exception) {
            QUI\System\Log::addError(
                'Amazon Pay :: Cannot check if payment process for Order #'.$hash.' is successful'
                .' -> '.$Exception->getMessage()
            );

            return false;
        }

        return $Order->getPaymentDataEntry(self::ATTR_ORDER_AUTHORIZED);
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
     * @return void
     *
     * @throws QUI\ERP\Accounting\Payments\Transactions\Exception
     * @throws QUI\Exception
     */
    public function executeGatewayPayment(QUI\ERP\Accounting\Payments\Gateway\Gateway $Gateway)
    {
        $AmazonPay = $this->getAmazonPayClient();
        $Order     = $Gateway->getOrder();

        $Order->addHistory('Amazon Pay :: Check if payment from Amazon was successful');

        $amazonCaptureId = $Order->getPaymentDataEntry(self::ATTR_AMAZON_CAPTURE_ID);

        $Response = $AmazonPay->getCaptureDetails([
            'amazon_capture_id' => $amazonCaptureId
        ]);

        try {
            $response = $this->getResponseData($Response);
        } catch (AmazonPayException $Exception) {
            $Order->addHistory(
                'Amazon Pay :: An error occurred while trying to validate the Capture -> '.$Exception->getMessage()
            );

            $Order->update(QUI::getUsers()->getSystemUser());
            return;
        }

        // check the amount that has already been captured
        $PriceCalculation   = $Order->getPriceCalculation();
        $targetSum          = $PriceCalculation->getSum()->precision(2)->get();
        $targetCurrencyCode = $Order->getCurrency()->getCode();

        $captureData        = $response['GetCaptureDetailsResult']['CaptureDetails'];
        $actualSum          = $captureData['CaptureAmount']['Amount'];
        $actualCurrencyCode = $captureData['CaptureAmount']['CurrencyCode'];

        if ($actualSum < $targetSum) {
            $Order->addHistory(
                'Amazon Pay :: The amount that was captured from Amazon was less than the'
                .' total sum of the order. Total sum: '.$targetSum.' '.$targetCurrencyCode
                .' | Actual sum captured by Amazon: '.$actualSum.' '.$actualCurrencyCode
            );

            $Order->update(QUI::getUsers()->getSystemUser());
            return;
        }

        // book payment in QUIQQER ERP
        $Order->addHistory('Amazon Pay :: Finalize Order payment');

        $Transaction = $Gateway->purchase(
            $actualSum,
            new QUI\ERP\Currency\Currency($actualCurrencyCode),
            $Order,
            $this
        );

        $Transaction->setData(
            self::ATTR_AMAZON_ORDER_REFERENCE_ID,
            $Order->getPaymentDataEntry(self::ATTR_AMAZON_ORDER_REFERENCE_ID)
        );

        $Transaction->setData(
            self::ATTR_AMAZON_AUTHORIZATION_ID,
            $Order->getPaymentDataEntry(self::ATTR_AMAZON_AUTHORIZATION_ID)
        );

        $Transaction->setData(
            self::ATTR_AMAZON_CAPTURE_ID,
            $Order->getPaymentDataEntry(self::ATTR_AMAZON_CAPTURE_ID)
        );

        $Transaction->updateData();

        $Order->addHistory('Amazon Pay :: Closing OrderReference');
        $this->closeOrderReference($Order);
        $Order->addHistory('Amazon Pay :: Order successfully paid');

        $Order->update(QUI::getUsers()->getSystemUser());
    }

    /**
     * Execute a refund
     *
     * @param QUI\ERP\Accounting\Payments\Transactions\Transaction $Transaction
     * @param int|float $amount
     * @param string $message
     * @param false|string $hash - if a new hash will be used
     * @throws QUI\ERP\Accounting\Payments\Transactions\RefundException
     */
    public function refund(
        Transaction $Transaction,
        $amount,
        $message = '',
        $hash = false
    ) {
        if (!Provider::isIpnHandlingActivated()) {
            throw new QUI\ERP\Accounting\Payments\Transactions\RefundException([
                'quiqqer/payment-amazon',
                'exception.Payment.refund.ipn_not_activated'
            ]);
        }

        try {
            if ($hash === false) {
                $hash = $Transaction->getHash();
            }

            $this->refundPayment($Transaction, $hash, $amount, $message);
        } catch (AmazonPayException $Exception) {
            throw new QUI\ERP\Accounting\Payments\Transactions\RefundException([
                'quiqqer/payment-amazon',
                'exception.Payment.refund_error'
            ]);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            throw new QUI\ERP\Accounting\Payments\Transactions\RefundException([
                'quiqqer/payment-amazon',
                'exception.Payment.refund_error'
            ]);
        }
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
        $Step->setContent($Engine->fetch(dirname(__FILE__).'/PaymentDisplay.Header.html'));

        return $Control->create();
    }

    /**
     * Authorize the payment for an Order with Amazon
     *
     * @param string $orderReferenceId
     * @param AbstractOrder $Order
     *
     * @throws AmazonPayException
     * @throws QUI\ERP\Exception
     * @throws QUI\Exception
     */
    public function authorizePayment($orderReferenceId, AbstractOrder $Order)
    {
        $Order->addHistory('Amazon Pay :: Authorize payment');

        if ($Order->getPaymentDataEntry(self::ATTR_ORDER_AUTHORIZED)) {
            $Order->addHistory('Amazon Pay :: Authorization already exist');
            return;
        }

        $AmazonPay        = $this->getAmazonPayClient();
        $PriceCalculation = $Order->getPriceCalculation();
        $reconfirmOrder   = $Order->getPaymentDataEntry(self::ATTR_RECONFIRM_ORDER);

        // Re-confirm Order after previously declined Authorization because of "InvalidPaymentMethod"
        if ($reconfirmOrder) {
            $Order->addHistory(
                'Amazon Pay :: Re-confirm Order after declined Authorization because of "InvalidPaymentMethod"'
            );

            $orderReferenceId = $Order->getPaymentDataEntry(self::ATTR_AMAZON_ORDER_REFERENCE_ID);

            $Response = $AmazonPay->confirmOrderReference([
                'amazon_order_reference_id' => $orderReferenceId
            ]);

            $this->getResponseData($Response); // check response data

            $Order->setPaymentData(self::ATTR_RECONFIRM_ORDER, false);

            $Order->addHistory('Amazon Pay :: OrderReference re-confirmed');
        } elseif (!$Order->getPaymentDataEntry(self::ATTR_ORDER_REFERENCE_SET)) {
            $Order->addHistory(
                'Amazon Pay :: Setting details of the Order to Amazon Pay API'
            );

            $Response = $AmazonPay->setOrderReferenceDetails([
                'amazon_order_reference_id' => $orderReferenceId,
                'amount'                    => $PriceCalculation->getSum()->precision(2)->get(),
                'currency_code'             => $Order->getCurrency()->getCode(),
                'seller_order_id'           => $Order->getPrefixedId(),
                'seller_note'               => $this->getSellerNote($Order),
                'custom_information'        => QUI::getLocale()->get(
                    'quiqqer/payment-amazon',
                    'Payment.order_custom_information',
                    [
                        'orderHash' => $Order->getHash()
                    ]
                )
            ]);

            $response              = $this->getResponseData($Response);
            $orderReferenceDetails = $response['SetOrderReferenceDetailsResult']['OrderReferenceDetails'];

            if (isset($orderReferenceDetails['Constraints']['Constraint']['ConstraintID'])) {
                $Order->addHistory(
                    'Amazon Pay :: An error occurred while setting the details of the Order: "'
                    .$orderReferenceDetails['Constraints']['Constraint']['ConstraintID'].'""'
                );

                $this->throwAmazonPayException(
                    $orderReferenceDetails['Constraints']['Constraint']['ConstraintID'],
                    [
                        'reRenderWallet' => 1
                    ]
                );
            }

            $AmazonPay->confirmOrderReference([
                'amazon_order_reference_id' => $orderReferenceId
            ]);

            $Order->setPaymentData(self::ATTR_ORDER_REFERENCE_SET, true);
            $Order->update(QUI::getUsers()->getSystemUser());
        }

        $Order->addHistory('Amazon Pay :: Requesting new Authorization');

        $authorizationReferenceId = $this->getNewAuthorizationReferenceId($Order);

        $Response = $AmazonPay->authorize([
            'amazon_order_reference_id'  => $orderReferenceId,
            'authorization_amount'       => $PriceCalculation->getSum()->precision(2)->get(),
            'currency_code'              => $Order->getCurrency()->getCode(),
            'authorization_reference_id' => $authorizationReferenceId,
            'transaction_timeout'        => 0  // get authorization status synchronously
        ]);

        $response = $this->getResponseData($Response);

        // save reference ids in $Order
        $authorizationDetails  = $response['AuthorizeResult']['AuthorizationDetails'];
        $amazonAuthorizationId = $authorizationDetails['AmazonAuthorizationId'];

        $this->addAuthorizationReferenceIdToOrder($authorizationReferenceId, $Order);
        $Order->setPaymentData(self::ATTR_AMAZON_AUTHORIZATION_ID, $amazonAuthorizationId);
        $Order->setPaymentData(self::ATTR_AMAZON_ORDER_REFERENCE_ID, $orderReferenceId);
        $Order->addHistory(
            QUI::getLocale()->get(
                'quiqqer/payment-amazon',
                'history.order_reference_id',
                [
                    'orderReferenceId' => $orderReferenceId
                ]
            )
        );

        $Order->update(QUI::getUsers()->getSystemUser());

        // check Authorization
        $Order->addHistory('Amazon Pay :: Checking Authorization status');

        $status = $authorizationDetails['AuthorizationStatus'];
        $state  = $status['State'];

        switch ($state) {
            case 'Open':
                // everything is fine
                $Order->addHistory(
                    'Amazon Pay :: Authorization is OPEN an can be used for capturing'
                );

                $Order->setPaymentData(self::ATTR_ORDER_AUTHORIZED, true);
                $Order->update(QUI::getUsers()->getSystemUser());
                break;

            case 'Declined':
                $reason = $status['ReasonCode'];

                switch ($reason) {
                    case 'InvalidPaymentMethod':
                        $Order->addHistory(
                            'Amazon Pay :: Authorization was DECLINED. User has to choose another payment method.'
                            .' ReasonCode: "'.$reason.'"'
                        );

                        $Order->setPaymentData(self::ATTR_RECONFIRM_ORDER, true);
                        $Order->update(QUI::getUsers()->getSystemUser());

                        $this->throwAmazonPayException($reason, [
                            'reRenderWallet' => 1
                        ]);
                        break;

                    case 'TransactionTimedOut':
                        $Order->addHistory(
                            'Amazon Pay :: Authorization was DECLINED. User has to choose another payment method.'
                            .' ReasonCode: "'.$reason.'"'
                        );

                        $AmazonPay->cancelOrderReference([
                            'amazon_order_reference_id' => $orderReferenceId,
                            'cancelation_reason'        => 'Order #'.$Order->getHash().' could not be authorized :: TransactionTimedOut'
                        ]);

                        $Order->setPaymentData(self::ATTR_ORDER_REFERENCE_SET, false);
                        $Order->update(QUI::getUsers()->getSystemUser());

                        $this->throwAmazonPayException($reason, [
                            'reRenderWallet' => 1,
                            'orderCancelled' => 1
                        ]);
                        break;

                    default:
                        $Order->addHistory(
                            'Amazon Pay :: Authorization was DECLINED. OrderReference has to be closed. Cannot use Amazon Pay for this Order.'
                            .' ReasonCode: "'.$reason.'"'
                        );

                        $Response = $AmazonPay->getOrderReferenceDetails([
                            'amazon_order_reference_id' => $orderReferenceId
                        ]);

                        $response              = $Response->toArray();
                        $orderReferenceDetails = $response['GetOrderReferenceDetailsResult']['OrderReferenceDetails'];
                        $orderReferenceStatus  = $orderReferenceDetails['OrderReferenceStatus']['State'];

                        if ($orderReferenceStatus === 'Open') {
                            $AmazonPay->cancelOrderReference([
                                'amazon_order_reference_id' => $orderReferenceId,
                                'cancelation_reason'        => 'Order #'.$Order->getHash().' could not be authorized'
                            ]);

                            $Order->setPaymentData(self::ATTR_AMAZON_ORDER_REFERENCE_ID, false);
                        }

                        $Order->clearPayment();
                        $Order->update(QUI::getUsers()->getSystemUser());

                        $this->throwAmazonPayException($reason);
                }
                break;

            default:
                // @todo Order ggf. pending
                $reason = $status['ReasonCode'];

                $Order->addHistory(
                    'Amazon Pay :: Authorization cannot be used because it is in state "'.$state.'".'
                    .' ReasonCode: "'.$reason.'"'
                );

                $this->throwAmazonPayException($reason);
        }
    }

    /**
     * Capture the actual payment for an Order
     *
     * @param AbstractOrder $Order
     * @return void
     *
     * @throws AmazonPayException
     * @throws QUI\ERP\Exception
     * @throws QUI\Exception
     */
    public function capturePayment(AbstractOrder $Order)
    {
        $Order->addHistory('Amazon Pay :: Capture payment');

        if ($Order->getPaymentDataEntry(self::ATTR_ORDER_CAPTURED)) {
            $Order->addHistory('Amazon Pay :: Capture is already completed');
            return;
        }

        $AmazonPay        = $this->getAmazonPayClient();
        $orderReferenceId = $Order->getPaymentDataEntry(self::ATTR_AMAZON_ORDER_REFERENCE_ID);

        if (empty($orderReferenceId)) {
            $Order->addHistory(
                'Amazon Pay :: Capture failed because the Order has no AmazonOrderReferenceId'
            );

            throw new AmazonPayException([
                'quiqqer/payment-amazon',
                'exception.Payment.capture.not_authorized',
                [
                    'orderHash' => $Order->getHash()
                ]
            ]);
        }

        if (!$Order->getPaymentDataEntry(self::ATTR_AMAZON_AUTHORIZATION_ID)) {
            try {
                $this->authorizePayment($orderReferenceId, $Order);
            } catch (AmazonPayException $Exception) {
                $Order->addHistory(
                    'Amazon Pay :: Capture failed because the Order has no OPEN Authorization'
                );

                throw new AmazonPayException([
                    'quiqqer/payment-amazon',
                    'exception.Payment.capture.not_authorized',
                    [
                        'orderHash' => $Order->getHash()
                    ]
                ]);
            } catch (\Exception $Exception) {
                $Order->addHistory(
                    'Amazon Pay :: Capture failed because of an error: '.$Exception->getMessage()
                );

                QUI\System\Log::writeException($Exception);
                return;
            }
        }

        $PriceCalculation   = $Order->getPriceCalculation();
        $sum                = $PriceCalculation->getSum()->precision(2)->get();
        $captureReferenceId = $this->getNewCaptureReferenceId($Order);

        $Response = $AmazonPay->capture([
            'amazon_authorization_id' => $Order->getPaymentDataEntry(self::ATTR_AMAZON_AUTHORIZATION_ID),
            'capture_amount'          => $sum,
            'currency_code'           => $Order->getCurrency()->getCode(),
            'capture_reference_id'    => $captureReferenceId,
            'seller_capture_note'     => $this->getSellerNote($Order)
        ]);

        $response = $this->getResponseData($Response);

        $captureDetails  = $response['CaptureResult']['CaptureDetails'];
        $amazonCaptureId = $captureDetails['AmazonCaptureId'];

        $this->addCaptureReferenceIdToOrder($amazonCaptureId, $Order);
        $Order->setPaymentData(self::ATTR_AMAZON_CAPTURE_ID, $amazonCaptureId);
        $Order->update(QUI::getUsers()->getSystemUser());

        // Check Capture
        $Order->addHistory('Amazon Pay :: Checking Capture status');

        $status = $captureDetails['CaptureStatus'];
        $state  = $status['State'];

        switch ($state) {
            case 'Completed':
                $Order->addHistory(
                    'Amazon Pay :: Capture is COMPLETED -> '.$sum.' '.$Order->getCurrency()->getCode()
                );

                $Order->setPaymentData(self::ATTR_ORDER_CAPTURED, true);
                $Order->update(QUI::getUsers()->getSystemUser());

                $Gateway = Gateway::getInstance();
                $Gateway->setOrder($Order);

                $this->executeGatewayPayment($Gateway);
                break;

            case 'Pending':
                // @todo pending status
                if (Provider::isIpnHandlingActivated()) {
                    // etc.
                }
                break;

            default:
                $reason = $status['ReasonCode'];

                $Order->addHistory(
                    'Amazon Pay :: Capture operation failed with state "'.$state.'".'
                    .' ReasonCode: "'.$reason.'"'
                );

                // @todo Change order status to "problems with Amazon Payment"

                $this->throwAmazonPayException($reason);
                break;
        }
    }

    /**
     * Refund partial or full payment of an Order
     *
     * @param QUI\ERP\Accounting\Payments\Transactions\Transaction $Transaction
     * @param string $refundHash - Hash of the refund Transaction
     * @param float $amount - The amount to be refunden
     * @param string $reason (optional) - The reason for the refund [default: none; max. 255 characters]
     * @return void
     *
     * @throws AmazonPayException
     * @throws QUI\Exception
     */
    public function refundPayment(Transaction $Transaction, $refundHash, $amount, $reason = '')
    {
        $Process = new QUI\ERP\Process($Transaction->getGlobalProcessId());
        $Process->addHistory('Amazon Pay :: Start refund for transaction #'.$Transaction->getTxId());

        $amazonCaptureId = $Transaction->getData(self::ATTR_AMAZON_CAPTURE_ID);

        if (empty($amazonCaptureId)) {
            $Process->addHistory('Amazon Pay :: Transaction cannot be refunded because it is not yet captured / completed');

            throw new AmazonPayException([
                'quiqqer/payment-amazon',
                'exception.Payment.refund_order_not_captured'
            ]);
        }

        // create a refund transaction
        $RefundTransaction = TransactionFactory::createPaymentRefundTransaction(
            $amount,
            $Transaction->getCurrency(),
            $refundHash,
            $Transaction->getPayment()->getName(),
            [
                'isRefund' => 1,
                'message'  => $reason
            ],
            null,
            false,
            $Transaction->getGlobalProcessId()
        );

        $RefundTransaction->pending();

        $AmazonPay         = $this->getAmazonPayClient();
        $AmountValue       = new QUI\ERP\Accounting\CalculationValue($amount, $Transaction->getCurrency(), 2);
        $sum               = $AmountValue->get();
        $refundReferenceId = $this->generateRefundReferenceId($RefundTransaction);

        $Response = $AmazonPay->refund([
            'amazon_capture_id'   => $amazonCaptureId,
            'refund_reference_id' => $refundReferenceId,
            'refund_amount'       => $sum,
            'currency_code'       => $Transaction->getCurrency()->getCode(),
            'seller_refund_note'  => mb_substr($reason, 0, 255)
        ]);

        try {
            $response = $this->getResponseData($Response);
        } catch (AmazonPayException $Exception) {
            $Process->addHistory(
                'Amazon Pay :: Refund operation failed.'
                .' Reason: "'.$Exception->getMessage().'".'
                .' ReasonCode: "'.$Exception->getCode().'".'
                .' Transaction #'.$Transaction->getTxId()
            );

            $RefundTransaction->error();

            throw $Exception;
        }

        $refundDetails  = $response['RefundResult']['RefundDetails'];
        $amazonRefundId = $refundDetails['AmazonRefundId'];

        $RefundTransaction->setData(self::ATTR_AMAZON_REFUND_ID, $amazonRefundId);
        $RefundTransaction->updateData();

        // Check Capture
        $Process->addHistory('Amazon Pay :: Checking refund status for transaction #'.$RefundTransaction->getTxId());

        $status = $refundDetails['RefundStatus'];
        $state  = $status['State'];

        switch ($state) {
            case 'Pending':
                $Process->addHistory(
                    'Amazon Pay :: Refund for '.$AmountValue->formatted().' successfully started.'
                    .' Waiting for Amazon answer via IPN. Transaction #'.$RefundTransaction->getTxId()
                );
                break;

            default:
                $reason = $status['ReasonCode'];

                $Process->addHistory(
                    'Amazon Pay :: Refund operation failed with state "'.$state.'".'
                    .' ReasonCode: "'.$reason.'".'
                    .' Transaction #'.$RefundTransaction->getTxId()
                );

                $RefundTransaction->error();
                $this->throwAmazonPayException($reason);
                break;
        }
    }

    /**
     * Finalize a refund process
     *
     * @param QUI\ERP\Accounting\Payments\Transactions\Transaction $RefundTransaction
     * @param array $amazonIpnData
     * @return void
     * @throws \QUI\Exception
     */
    public function finalizeRefund(Transaction $RefundTransaction, $amazonIpnData)
    {
        // finalize refund
        $Process = new QUI\ERP\Process($RefundTransaction->getGlobalProcessId());

        if ($RefundTransaction->getStatus() === TransactionHandler::STATUS_COMPLETE) {
            return;
        }

        $Process->addHistory('Amazon Pay :: Refund IPN answer received for transaction #'.$RefundTransaction->getTxId());
        $refundDetails = $amazonIpnData['RefundDetails'];

        switch ($refundDetails['RefundStatus']['State']) {
            case 'Declined':
                $Process->addHistory(
                    'Amazon Pay :: Refund transaction #'.$RefundTransaction->getTxId().' was DECLINED by Amazon.'
                    .' ReasonCode: "'.$refundDetails['RefundStatus']['ReasonCode'].'".'
                );

                $RefundTransaction->error();
                break;

            case 'Completed':
                $Process->addHistory(
                    'Amazon Pay :: Refund transaction #'.$RefundTransaction->getTxId().' was SUCCESSFUL.'
                    .' Amount refunded: '.$refundDetails['RefundAmount']['Amount'].' '.$refundDetails['RefundAmount']['CurrencyCode'].'.'
                );

                $RefundTransaction->complete();

                QUI::getEvents()->fireEvent('transactionSuccessfullyRefunded', [
                    $RefundTransaction,
                    $this
                ]);
                break;
        }
    }

    /**
     * Set the Amazon Pay OrderReference to status CLOSED
     *
     * @param AbstractOrder $Order
     * @param string $reason (optional) - Close reason [default: "Order #hash completed"]
     * @return void
     */
    protected function closeOrderReference(AbstractOrder $Order, $reason = null)
    {
        $AmazonPay        = $this->getAmazonPayClient();
        $orderReferenceId = $Order->getPaymentDataEntry(self::ATTR_AMAZON_ORDER_REFERENCE_ID);

        $AmazonPay->closeOrderReference([
            'amazon_order_reference_id' => $orderReferenceId,
            'closure_reason'            => $reason ?: 'Order #'.$Order->getHash().' completed'
        ]);
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
            $this->throwAmazonPayException(
                $response['Error']['Code'],
                [
                    'amazonApiErrorCode' => $response['Error']['Code'],
                    'amazonApiErrorMsg'  => $response['Error']['Message']
                ]
            );
        }

        return $response;
    }

    /**
     * Throw AmazonPayException for specific Amazon API Error
     *
     * @param string $errorCode
     * @param array $exceptionAttributes (optional) - Additional Exception attributes that may be relevant for the Frontend
     * @return string
     *
     * @throws AmazonPayException
     */
    protected function throwAmazonPayException($errorCode, $exceptionAttributes = [])
    {
        $L   = $this->getLocale();
        $lg  = 'quiqqer/payment-amazon';
        $msg = $L->get($lg, 'payment.error_msg.general_error');

        switch ($errorCode) {
            case 'InvalidPaymentMethod':
            case 'PaymentMethodNotAllowed':
            case 'TransactionTimedOut':
            case 'AmazonRejected':
            case 'ProcessingFailure':
            case 'MaxCapturesProcessed':
                $msg = $L->get($lg, 'payment.error_msg.'.$errorCode);
                break;
        }

        $Exception = new AmazonPayException($msg);
        $Exception->setAttributes($exceptionAttributes);

        QUI\System\Log::writeException($Exception, QUI\System\Log::LEVEL_DEBUG, $exceptionAttributes);

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
        return mb_substr('a_'.$Order->getId().'_'.uniqid(), 0, 32);
    }

    /**
     * Add an AuthorizationReferenceId to current Order
     *
     * @param string $authorizationReferenceId
     * @param AbstractOrder $Order
     * @return void
     */
    protected function addAuthorizationReferenceIdToOrder($authorizationReferenceId, AbstractOrder $Order)
    {
        $authorizationReferenceIds = $Order->getPaymentDataEntry(self::ATTR_AUTHORIZATION_REFERENCE_IDS);

        if (empty($authorizationReferenceIds)) {
            $authorizationReferenceIds = [];
        }

        $authorizationReferenceIds[] = $authorizationReferenceId;

        $Order->setPaymentData(self::ATTR_AUTHORIZATION_REFERENCE_IDS, $authorizationReferenceIds);
        $Order->update(QUI::getUsers()->getSystemUser());
    }

    /**
     * Add an AmazonRefundId to current Order
     *
     * @param string $amazonRefundId
     * @param string $refundReferenceId
     * @param AbstractOrder $Order
     * @return void
     */
    protected function addAmazonRefundDetails($amazonRefundId, $refundReferenceId, AbstractOrder $Order)
    {
        $amazonRefundDetails = $Order->getPaymentDataEntry(self::ATTR_ORDER_REFUND_DETAILS);

        if (empty($amazonRefundDetails)) {
            $amazonRefundDetails = [];
        }

        $amazonRefundDetails[] = [
            'amazonRefundId'    => $amazonRefundId,
            'refundReferenceId' => $refundReferenceId,
            'completed'         => false
        ];

        $Order->setPaymentData(self::ATTR_ORDER_REFUND_DETAILS, $amazonRefundDetails);
        $this->saveOrder($Order);
    }

    /**
     * Generate a unique, random RefundReferenceId to identify
     * captures for an order
     *
     * @param QUI\ERP\Accounting\Payments\Transactions\Transaction $Transaction
     * @return string
     */
    protected function generateRefundReferenceId(Transaction $Transaction)
    {
        return str_replace('-', '', $Transaction->getTxId());
    }

    /**
     * Rebuild a cropped transaction id (transaction id without dashes)
     *
     * @param string $txId - Transaction id without dashes
     * @return string - Correct transaction id
     */
    public function rebuildCroppedTransactionId($txId)
    {
        $parts         = str_split($txId);
        $dashPositions = [8, 13, 18, 23];

        foreach ($dashPositions as $pos) {
            array_splice($parts, $pos, 0, '-');
        }

        return implode('', $parts);
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
        return mb_substr('c_'.$Order->getId().'_'.uniqid(), 0, 32);
    }

    /**
     * Add an CaptureReferenceId to current Order
     *
     * @param string $captureReferenceId
     * @param AbstractOrder $Order
     * @return void
     */
    protected function addCaptureReferenceIdToOrder($captureReferenceId, AbstractOrder $Order)
    {
        $captureReferenceIds = $Order->getPaymentDataEntry(self::ATTR_CAPTURE_REFERENCE_IDS);

        if (empty($captureReferenceIds)) {
            $captureReferenceIds = [];
        }

        $captureReferenceIds[] = $captureReferenceId;

        $Order->setPaymentData(self::ATTR_CAPTURE_REFERENCE_IDS, $captureReferenceIds);
        $this->saveOrder($Order);
    }

    /**
     * Get order seller note
     *
     * The seller note is a custom message that is shown to the customer
     * at their Amazon account for an oder
     *
     * @param AbstractOrder $Order
     * @return string
     * @throws QUI\Exception
     */
    protected function getSellerNote(AbstractOrder $Order)
    {
        $Conf        = QUI::getPackage('quiqqer/payment-amazon')->getConfig();
        $description = $Conf->get('payment', 'amazon_seller_note');

        if (empty($description)) {
            $description = [];
        } else {
            $description = json_decode($description, true);
        }

        $lang            = $Order->getCustomer()->getLang();
        $descriptionText = '';

        if (!empty($description[$lang])) {
            $descriptionText = str_replace(['{orderId}'], [$Order->getPrefixedId()], $description[$lang]);
        }

        // max length 255
        $descriptionText = mb_substr($descriptionText, 0, 255);

        return $descriptionText;
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

        $this->AmazonPayClient = new AmazonPayClient([
            'merchant_id' => Provider::getApiSetting('merchant_id'),
            'access_key'  => Provider::getApiSetting('access_key'),
            'secret_key'  => Provider::getApiSetting('secret_key'),
            'client_id'   => Provider::getApiSetting('client_id'),
            'sandbox'     => boolval(Provider::getApiSetting('sandbox')),
            'region'      => Provider::getApiSetting('region')
        ]);

        return $this->AmazonPayClient;
    }

    /**
     * Save Order with SystemUser
     *
     * @param AbstractOrder $Order
     * @return void
     */
    protected function saveOrder(AbstractOrder $Order)
    {
        $Order->update(QUI::getUsers()->getSystemUser());
    }

    /**
     * Add Amazon Pay history entry to a Process
     *
     * @param QUI\ERP\Process $Process
     * @param $message
     */
    protected function addProcessHistoryEntry(QUI\ERP\Process $Process, $message)
    {
        $Process->addHistory('Amazon Pay :: '.$message);
    }
}
