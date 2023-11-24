<?php

/**
 * This file contains QUI\ERP\Payments\Amazon\Recurring\BillingAgreements
 */

namespace QUI\ERP\Payments\Amazon\Recurring;

use QUI;
use QUI\ERP\Accounting\Invoice\Handler as InvoiceHandler;
use QUI\ERP\Accounting\Invoice\Invoice;
use QUI\ERP\Accounting\Payments\Payments;
use QUI\ERP\Accounting\Payments\Transactions\Factory as TransactionFactory;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\Amazon\AmazonPayException;
use QUI\ERP\Payments\Amazon\AmazonPayException as AmazonException;
use QUI\ERP\Payments\Amazon\Payment as BasePayment;
use QUI\ERP\Payments\Amazon\Utils;
use QUI\Utils\Security\Orthos;

/**
 * Class BillingAgreements
 *
 * Handler for Amazon Billing Agreement management
 */
class BillingAgreements
{
    const TBL_BILLING_AGREEMENTS = 'amazon_billing_agreements';
    const TBL_BILLING_AGREEMENT_TRANSACTIONS = 'amazon_billing_agreement_transactions';

    const BILLING_AGREEMENT_STATE_ACTIVE = 'Active';
    const BILLING_AGREEMENT_STATE_OPEN = 'Open';
    const BILLING_AGREEMENT_VALIDATION_SUCCESS = 'Success';

    const TRANSACTION_STATE_COMPLETED = 'Completed';
    const TRANSACTION_STATE_DENIED = 'Denied';

    const ATTR_BILLING_AGREEMENT_AUTHORIZATION_ID = 'amazon-AmazonBillingAgreementAuthorizationId';

    const EXCEPTION_CODE_BILLING_AGREEMENT_VALIDATION_ERROR = 630001;

    /**
     * Runtime cache that knows then a transaction history
     * for a Billing Agreement has been freshly fetched from Amazon.
     *
     * Prevents multiple unnecessary API calls.
     *
     * @var array
     */
    protected static $transactionsRefreshed = [];

    /**
     * @var QUI\ERP\Payments\Amazon\Payment
     */
    protected static $Payment = null;

    /**
     * Set details to an Amazon BillingAgreement based on order data
     *
     * @param QUI\ERP\Order\AbstractOrder $Order
     * @param string $billingAgreementId
     * @return void
     *
     * @throws \Exception
     */
    public static function setBillingAgreementDetails(string $billingAgreementId, AbstractOrder $Order)
    {
        $AmazonPay = BasePayment::getAmazonPayClient();

        $AmazonPay->setBillingAgreementDetails([
            'amazon_billing_agreement_id' => $billingAgreementId,
            'seller_note' => QUI::getLocale()->get(
                'quiqqer/payment-amazon',
                'recurring.BillingAgreement.seller_note',
                [
                    'orderId' => $Order->getPrefixedId(),
                    'url' => Utils::getProjectUrl()
                ]
            )
        ]);

        $Order->setAttribute(Payment::ATTR_AMAZON_BILLING_AGREEMENT_ID, $billingAgreementId);
        $Order->setPaymentData(Payment::ATTR_AMAZON_BILLING_AGREEMENT_ID, $billingAgreementId);
        $Order->addHistory(
            Utils::getHistoryText(
                'BillingAgreement.set_details',
                [
                    'billingAgreementId' => $billingAgreementId
                ]
            )
        );
        Utils::saveOrder($Order);
    }

    /**
     * Confirms that the billing agreement is free of constraints and all required information has been set on the billing agreement.
     *
     * Moves BillingAgreement to "OPEN" state.
     *
     * @param AbstractOrder $Order
     * @return void
     *
     * @throws \Exception
     */
    public static function confirmBillingAgreement(AbstractOrder $Order)
    {
        if ($Order->getPaymentDataEntry(Payment::ATTR_AMAZON_BILLING_AGREEMENT_CONFIRMED)) {
            return;
        }

        $AmazonPay = BasePayment::getAmazonPayClient();
        $billingAgreementId = $Order->getAttribute(Payment::ATTR_AMAZON_BILLING_AGREEMENT_ID);

        $AmazonPay->confirmBillingAgreement([
            'amazon_billing_agreement_id' => $billingAgreementId,
            'success_url' => Utils::getSuccessUrl($Order),
            'failure_url' => Utils::getFailureUrl($Order)
        ]);

        // Check if BillingAgreement is in OPEN state
        $Response = $AmazonPay->getBillingAgreementDetails([
            'amazon_billing_agreement_id' => $billingAgreementId
        ]);

        $details = $Response->toArray();
        $details = $details['GetBillingAgreementDetailsResult']['BillingAgreementDetails'];

        if (
            empty($details['BillingAgreementStatus']['State']) ||
            $details['BillingAgreementStatus']['State'] !== self::BILLING_AGREEMENT_STATE_OPEN
        ) {
            // Check if there are any constraints that prevent confirmation of the BillingAgreement
            if (!empty($details['Constraints'])) {
                $constraintId = $details['Constraints']['Constraint']['ConstraintID'];

                $Order->addHistory(
                    Utils::getHistoryText(
                        'BillingAgreement.confirm_billing_agreement_error',
                        [
                            'constraint' => $constraintId
                        ]
                    )
                );

                $exceptionMsg = self::getBillingAgreementConfirmationConstraintExceptionMessage($constraintId);
            } else {
                $Order->addHistory(
                    Utils::getHistoryText(
                        'BillingAgreement.confirm_billing_agreement_error',
                        [
                            'constraint' => 'unknown'
                        ]
                    )
                );

                $exceptionMsg = self::getBillingAgreementConfirmationConstraintExceptionMessage('general');
            }

            Utils::saveOrder($Order);

            throw new AmazonPayException($exceptionMsg);
        }

        $Order->addHistory(
            Utils::getHistoryText(
                'BillingAgreement.confirm_billing_agreement',
                [
                    'billingAgreementId' => $billingAgreementId
                ]
            )
        );

        $Order->setPaymentData(Payment::ATTR_AMAZON_BILLING_AGREEMENT_CONFIRMED, true);
        Utils::saveOrder($Order);
    }

    /**
     * Validate Amazon BillingAgreement
     *
     * @param AbstractOrder $Order
     * @return void
     *
     * @throws AmazonPayException
     * @throws QUI\Exception
     */
    public static function validateBillingAgreement(AbstractOrder $Order)
    {
        if ($Order->getPaymentDataEntry(Payment::ATTR_AMAZON_BILLING_AGREEMENT_VALIDATED)) {
            return;
        }

        $AmazonPay = BasePayment::getAmazonPayClient();

        $Response = $AmazonPay->validateBillingAgreement([
            'amazon_billing_agreement_id' => $Order->getAttribute(Payment::ATTR_AMAZON_BILLING_AGREEMENT_ID)
        ]);

        $result = $Response->toArray();
        $result = $result['ValidateBillingAgreementResult'];

        if (empty($result['ValidationResult']) || $result['ValidationResult'] !== self::BILLING_AGREEMENT_VALIDATION_SUCCESS) {
            $Order->addHistory(
                Utils::getHistoryText(
                    'BillingAgreement.validate_billing_agreement_error'
                )
            );

            Utils::saveOrder($Order);

            throw new AmazonPayException(
                [
                    'quiqqer/payment-amazon',
                    'exception.BillingAgreements.billing_agreement_not_validated'
                ],
                self::EXCEPTION_CODE_BILLING_AGREEMENT_VALIDATION_ERROR
            );
        }

        $Order->addHistory(
            Utils::getHistoryText(
                'BillingAgreement.validate_billing_agreement'
            )
        );

        $Order->setPaymentData(Payment::ATTR_AMAZON_BILLING_AGREEMENT_VALIDATED, true);
        Utils::saveOrder($Order);

        // Save billing agreement data in database
        $Customer = $Order->getCustomer();

        QUI::getDataBase()->insert(
            self::getBillingAgreementsTable(),
            [
                'amazon_agreement_id' => $Order->getAttribute(Payment::ATTR_AMAZON_BILLING_AGREEMENT_ID),
                'customer' => \json_encode($Customer->getAttributes()),
                'global_process_id' => $Order->getHash(),
                'active' => 1
            ]
        );
    }

    /**
     * Bills the balance for an agreement based on an Invoice
     *
     * @param Invoice $Invoice
     * @return void
     *
     * @throws AmazonException
     * @throws QUI\Exception
     */
    public static function billBillingAgreementBalance(Invoice $Invoice)
    {
        $billingAgreementId = $Invoice->getPaymentDataEntry(Payment::ATTR_AMAZON_BILLING_AGREEMENT_ID);

        if (empty($billingAgreementId)) {
            $Invoice->addHistory(
                Utils::getHistoryText('invoice.error.agreement_id_not_found')
            );

            throw new AmazonException(
                QUI::getLocale()->get(
                    'quiqqer/payment-amazon',
                    'exception.Recurring.agreement_id_not_found',
                    [
                        'invoiceId' => $Invoice->getId()
                    ]
                ),
                404
            );
        }

        $data = self::getQuiqqerBillingAgreementData($billingAgreementId);

        if ($data === false) {
            $Invoice->addHistory(
                Utils::getHistoryText('invoice.error.agreement_not_found', [
                    'billingAgreementId' => $billingAgreementId
                ])
            );

            throw new AmazonException(
                QUI::getLocale()->get(
                    'quiqqer/payment-amazon',
                    'exception.Recurring.agreement_not_found',
                    [
                        'billingAgreementId' => $billingAgreementId
                    ]
                ),
                404
            );
        }

        // Do not process invoices for inactive billing agreements
        if (!$data['active']) {
            return;
        }

        if (self::isSuspended($billingAgreementId)) {
            return;
        }

        if (!self::isBillingAgreementActiveAtAmazon($billingAgreementId)) {
            return;
        }

        // Check if a Billing Agreement transaction matches the Invoice
        $transactionData = self::getBillingAgreementTransactionData($billingAgreementId, $Invoice->getCleanId());

        // If no transaction data found -> create DB entry
        if ($transactionData === false) {
            QUI::getDataBase()->insert(
                self::getBillingAgreementTransactionsTable(),
                [
                    'invoice_id' => $Invoice->getCleanId(),
                    'amazon_agreement_id' => $billingAgreementId,
                    'global_process_id' => $Invoice->getGlobalProcessId()
                ]
            );
        }

        $invoiceAmount = Utils::getFormattedPriceByInvoice($Invoice);
        $invoiceCurrency = $Invoice->getCurrency()->getCode();
        $Payment = new Payment();
        $AmazonPay = BasePayment::getAmazonPayClient();
        $createNewAuthorization = true;

        /**
         * If an authorization for this invoice already exists -> try to capture if it is
         * still in the "Open" state.
         */
        if (!empty($transactionData['amazon_authorization_id'])) {
            $Response = $AmazonPay->getAuthorizationDetails([
                'amazon_authorization_id' => $transactionData['amazon_authorization_id']
            ]);

            $data = Utils::getResponseData($Response);
            $data = $data['GetAuthorizationDetailsResult']['AuthorizationDetails'];

            if ($data['AuthorizationStatus']['State'] === 'Open') {
                // Capture open authorization
                $CaptureResponse = $AmazonPay->capture([
                    'amazon_authorization_id' => $transactionData['amazon_authorization_id'],
                    'capture_amount' => $invoiceAmount,
                    'currency_code' => $invoiceCurrency,
                    'capture_reference_id' => Utils::formatApiString($Invoice->getId(), 32)
                ]);

                $captureData = Utils::getResponseData($CaptureResponse);
                $captureData = $captureData['CaptureResult']['CaptureDetails'];
                $capturedAmount = $captureData['CaptureAmount'];

                $createNewAuthorization = false;
            }
        }

        /**
         * If there is no existing authorization or the existing authorization could not be
         * captured -> created a new one and capture immediately.
         */
        if ($createNewAuthorization) {
            $Response = $AmazonPay->authorizeOnBillingAgreement([
                'amazon_billing_agreement_id' => $billingAgreementId,
                'authorization_reference_id' => Utils::formatApiString($Invoice->getId(), 32),
                'authorization_amount' => $invoiceAmount,
                'currency_code' => $invoiceCurrency,
                // immediately capture amount
                'capture_now' => true,
                // synchronous mode; https://developer.amazon.com/de/docs/amazon-pay-automatic/sync-modes.html
                'transaction_timeout' => 0,
                'seller_authorization_note' => QUI::getLocale()->get(
                    'quiqqer/payment-amazon',
                    'recurring.BillingAgreement.seller_authorization_note',
                    [
                        'url' => Utils::getProjectUrl(),
                        'invoiceId' => $Invoice->getId()
                    ]
                )
            ]);

            $data = Utils::getResponseData($Response);
            $data = $data['AuthorizeOnBillingAgreementResult']['AuthorizationDetails'];

            // Save authorization and capture ID


            QUI::getDataBase()->update(
                self::getBillingAgreementTransactionsTable(),
                [
                    'amazon_authorization_id' => $data['AmazonAuthorizationId']
                ],
                [
                    'invoice_id' => $Invoice->getCleanId()
                ]
            );

            $capturedAmount = $data['CapturedAmount'];
        }

        $captureAttempts = $transactionData ? $transactionData['capture_attempts'] : 0;
        $captureAttempts++;

        /**
         * Check if captures amount matches the invoice amount and currency
         */
        if ($capturedAmount['Amount'] !== $invoiceAmount || $capturedAmount['CurrencyCode'] !== $invoiceCurrency) {
            $Invoice->addHistory(
                Utils::getHistoryText('invoice.error.agreement_capture_failed', [
                    'billingAgreementId' => $billingAgreementId
                ])
            );

            // Increase capture attempts
            if ($captureAttempts >= self::getBillingAgreementMaxCaptureAttempts()) {
                self::cancelBillingAgreement(
                    $billingAgreementId,
                    QUI::getLocale()->get(
                        'quiqqer/payment-amazon',
                        'message.BillingAgreements.agreement_cancel.max_capture_attempts_exceeded',
                        [
                            'attempts' => $captureAttempts
                        ]
                    )
                );

                $Invoice->addHistory(
                    Utils::getHistoryText('invoice.error.agreement_cancel_max_capture_attempts_exceeded', [
                        'billingAgreementId' => $billingAgreementId,
                        'attempts' => $captureAttempts
                    ])
                );
            }

            QUI::getDataBase()->update(
                self::getBillingAgreementTransactionsTable(),
                [
                    'capture_attempts' => $captureAttempts
                ],
                [
                    'invoice_id' => $Invoice->getCleanId(),
                    'amazon_agreement_id' => $billingAgreementId
                ]
            );

            return;
        }

        // Transaction amount equals Invoice amount
        try {
            $InvoiceTransaction = TransactionFactory::createPaymentTransaction(
                $invoiceAmount,
                $Invoice->getCurrency(),
                $Invoice->getHash(),
                $Payment->getName(),
                [
                    self::ATTR_BILLING_AGREEMENT_AUTHORIZATION_ID => $data['AmazonAuthorizationId']
                ],
                null,
                null,
                $Invoice->getGlobalProcessId()
            );

            $InvoiceTransaction->setData(BasePayment::ATTR_AMAZON_CAPTURE_ID, $data['IdList']['member']);
            $InvoiceTransaction->updateData();

            $Invoice->addTransaction($InvoiceTransaction);

            $TransactionDate = \date_create($data['CreationTimestamp']);

            QUI::getDataBase()->update(
                self::getBillingAgreementTransactionsTable(),
                [
                    'quiqqer_transaction_id' => $InvoiceTransaction->getTxId(),
                    'quiqqer_transaction_completed' => 1,
                    'amazon_transaction_data' => \json_encode($data),
                    'amazon_transaction_date' => $TransactionDate->format('Y-m-d H:i:s'),
                    'capture_attempts' => $captureAttempts,
                ],
                [
                    'invoice_id' => $Invoice->getCleanId(),
                    'amazon_agreement_id' => $billingAgreementId
                ]
            );

            $Invoice->addHistory(
                Utils::getHistoryText('invoice.add_amazon_transaction', [
                    'quiqqerTransactionId' => $InvoiceTransaction->getTxId(),
                    'amazonAuthorizationId' => $data['AmazonAuthorizationId'],
                    'billingAgreementId' => $billingAgreementId
                ])
            );
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }
    }

    /**
     * Authorize and capture a payment against a BillingAgreement
     *
     * @param Invoice $Invoice
     * @return void
     */
    public static function authorizeBillingAgreementPayment(Invoice $Invoice)
    {
    }

    /**
     * Suspend a Subscription
     *
     * This *temporarily* suspends the automated collection of payments until explicitly resumed.
     *
     * @param int|string $subscriptionId
     * @return void
     *
     * @throws QUI\Database\Exception
     */
    public static function suspendSubscription($subscriptionId)
    {
        if (self::isSuspended($subscriptionId)) {
            return;
        }

        QUI::getDataBase()->update(
            self::getBillingAgreementsTable(),
            [
                'suspended' => 1
            ],
            [
                'amazon_agreement_id' => $subscriptionId
            ]
        );
    }

    /**
     * Resume a suspended Subscription
     *
     * This resumes automated collection of payments of a previously supsendes Subscription.
     *
     * @param int|string $subscriptionId
     * @return void
     *
     * @throws QUI\Database\Exception
     */
    public static function resumeSubscription($subscriptionId)
    {
        if (!self::isSuspended($subscriptionId)) {
            return;
        }

        QUI::getDataBase()->update(
            self::getBillingAgreementsTable(),
            [
                'suspended' => 0
            ],
            [
                'amazon_agreement_id' => $subscriptionId
            ]
        );
    }

    /**
     * Checks if a subscription is currently suspended
     *
     * @param int|string $subscriptionId
     * @return bool
     *
     * @throws QUI\Database\Exception
     */
    public static function isSuspended($subscriptionId)
    {
        $result = QUI::getDataBase()->fetch([
            'select' => ['suspended'],
            'from' => self::getBillingAgreementsTable(),
            'where' => [
                'amazon_agreement_id' => $subscriptionId
            ],
            'limit' => 1
        ]);

        if (empty($result)) {
            return false;
        }

        return !empty($result[0]['suspended']);
    }

    /**
     * Get data of all Billing Agreements (QUIQQER data only; no Amazon query performed!)
     *
     * @param array $searchParams
     * @param bool $countOnly (optional) - Return count of all results
     * @return array|int
     */
    public static function getBillingAgreementList($searchParams, $countOnly = false)
    {
        $Grid = new QUI\Utils\Grid($searchParams);
        $gridParams = $Grid->parseDBParams($searchParams);

        $binds = [];
        $where = [];

        if ($countOnly) {
            $sql = "SELECT COUNT(amazon_agreement_id)";
        } else {
            $sql = "SELECT *";
        }

        $sql .= " FROM `" . self::getBillingAgreementsTable() . "`";

        if (!empty($searchParams['search'])) {
            $where[] = '`global_process_id` LIKE :search';

            $binds['search'] = [
                'value' => '%' . $searchParams['search'] . '%',
                'type' => \PDO::PARAM_STR
            ];
        }

        // build WHERE query string
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        // ORDER
        if (!empty($searchParams['sortOn'])
        ) {
            $sortOn = Orthos::clear($searchParams['sortOn']);
            $order = "ORDER BY " . $sortOn;

            if (
                isset($searchParams['sortBy']) &&
                !empty($searchParams['sortBy'])
            ) {
                $order .= " " . Orthos::clear($searchParams['sortBy']);
            } else {
                $order .= " ASC";
            }

            $sql .= " " . $order;
        }

        // LIMIT
        if (
            !empty($gridParams['limit'])
            && !$countOnly
        ) {
            $sql .= " LIMIT " . $gridParams['limit'];
        } else {
            if (!$countOnly) {
                $sql .= " LIMIT " . (int)20;
            }
        }

        $Stmt = QUI::getPDO()->prepare($sql);

        // bind search values
        foreach ($binds as $var => $bind) {
            $Stmt->bindValue(':' . $var, $bind['value'], $bind['type']);
        }

        try {
            $Stmt->execute();
            $result = $Stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return [];
        }

        if ($countOnly) {
            return (int)current(current($result));
        }

        return $result;
    }

    /**
     * Get billing agreement transaction data by invoice
     *
     * @param string $billingAgreementId
     * @param int $invoiceId
     * @return array|false - Transaction data or false if not yet created
     */
    public static function getBillingAgreementTransactionData(string $billingAgreementId, int $invoiceId)
    {
        try {
            $result = QUI::getDataBase()->fetch([
                'from' => self::getBillingAgreementTransactionsTable(),
                'where' => [
                    'amazon_agreement_id' => $billingAgreementId,
                    'invoice_id' => $invoiceId
                ]
            ]);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        if (empty($result)) {
            return false;
        }

        return \current($result);
    }

    /**
     * Set status of a BillingAgreement as inactive (QUIQQER and Amazon)
     *
     * @param string $billingAgreementId
     * @param string $reason (optional) - Reason for deactivation (max. 1024 characters)
     * @return void
     */
    public static function cancelBillingAgreement($billingAgreementId, string $reason = null)
    {
        try {
            $AmazonPay = BasePayment::getAmazonPayClient();

            $closeArguments = [
                'amazon_billing_agreement_id' => $billingAgreementId
            ];

            if (!empty($reason)) {
                $closeArguments['closure_reason'] = \mb_substr($reason, 0, 1024);
            }

            $AmazonPay->closeBillingAgreement($closeArguments);

            QUI::getDataBase()->update(
                self::getBillingAgreementsTable(),
                [
                    'active' => 0
                ],
                [
                    'amazon_agreement_id' => $billingAgreementId
                ]
            );
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }
    }

    /**
     * Checks if the subscription is active at the Amazon side
     *
     * @param string $billingAgreementId
     * @return bool
     */
    public static function isBillingAgreementActiveAtAmazon(string $billingAgreementId)
    {
        try {
            $data = BillingAgreements::getAmazonBillingAgreementData($billingAgreementId);
            return $data['BillingAgreementStatus']['State'] === 'Open';
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return true;
        }
    }

    /**
     * Process all unpaid Invoices of Billing Agreements
     *
     * @return void
     */
    public static function processUnpaidInvoices()
    {
        $Invoices = InvoiceHandler::getInstance();

        // Determine payment type IDs
        $payments = Payments::getInstance()->getPayments([
            'select' => ['id'],
            'where' => [
                'payment_type' => Payment::class
            ]
        ]);

        $paymentTypeIds = [];

        /** @var QUI\ERP\Accounting\Payments\Types\Payment $Payment */
        foreach ($payments as $Payment) {
            $paymentTypeIds[] = $Payment->getId();
        }

        if (empty($paymentTypeIds)) {
            return;
        }

        // Get all unpaid Invoices
        $result = $Invoices->search([
            'select' => ['id', 'global_process_id'],
            'where' => [
                'paid_status' => 0,
                'type' => InvoiceHandler::TYPE_INVOICE,
                'payment_method' => [
                    'type' => 'IN',
                    'value' => $paymentTypeIds
                ]
            ],
            'order' => 'date ASC'
        ]);

        $invoiceIds = [];

        foreach ($result as $row) {
            $globalProcessId = $row['global_process_id'];

            if (!isset($invoiceIds[$globalProcessId])) {
                $invoiceIds[$globalProcessId] = [];
            }

            $invoiceIds[$globalProcessId][] = $row['id'];
        }

        if (empty($invoiceIds)) {
            return;
        }

        // Determine relevant Billing Agreements
        try {
            $result = QUI::getDataBase()->fetch([
                'select' => ['global_process_id'],
                'from' => self::getBillingAgreementsTable(),
                'where' => [
                    'global_process_id' => [
                        'type' => 'IN',
                        'value' => array_keys($invoiceIds)
                    ]
                ]
            ]);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return;
        }

        // Refresh Billing Agreement transactions
        foreach ($result as $row) {
            // Handle invoices
            foreach ($invoiceIds as $globalProcessId => $invoices) {
                if ($row['global_process_id'] !== $globalProcessId) {
                    continue;
                }

                foreach ($invoices as $invoiceId) {
                    try {
                        $Invoice = $Invoices->get($invoiceId);

                        // Second: Process all completed transactions for Invoice
                        self::billBillingAgreementBalance($Invoice);
                    } catch (\Exception $Exception) {
                        QUI\System\Log::writeException($Exception);
                    }
                }
            }
        }
    }

    /**
     * Get available data by Billing Agreement ID (QUIQQER data)
     *
     * @param string $billingAgreementId - Amazon Billing Agreement ID
     * @return array|false
     */
    public static function getQuiqqerBillingAgreementData(string $billingAgreementId)
    {
        try {
            $result = QUI::getDataBase()->fetch([
                'from' => self::getBillingAgreementsTable(),
                'where' => [
                    'amazon_agreement_id' => $billingAgreementId
                ]
            ]);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        if (empty($result)) {
            return false;
        }

        $data = current($result);

        return [
            'active' => !empty($data['active']),
            'globalProcessId' => $data['global_process_id'],
            'customer' => json_decode($data['customer'], true)
        ];
    }

    /**
     * Get available data by Billing Agreement ID (Amazon data)
     *
     * @param string $billingAgreementId - Amazon Billing Agreement ID
     * @return array|false
     *
     * @throws \Exception
     */
    public static function getAmazonBillingAgreementData(string $billingAgreementId)
    {
        $AmazonPay = BasePayment::getAmazonPayClient();

        $Response = $AmazonPay->getBillingAgreementDetails([
            'amazon_billing_agreement_id' => $billingAgreementId
        ]);

        $data = $Response->toArray();

        return $data['GetBillingAgreementDetailsResult']['BillingAgreementDetails'];
    }

    /**
     * Get number of attempts for trying to capture funds from a BillingAgreement
     *
     * @return int
     */
    protected static function getBillingAgreementMaxCaptureAttempts()
    {
        try {
            return (int)QUI::getPackage('quiqqer/payment-amazon')->getConfig()->get(
                'billing_agreements',
                'max_capture_tries'
            );
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return 3; // fallback
        }
    }

    /**
     * @return string
     */
    public static function getBillingAgreementsTable()
    {
        return QUI::getDBTableName(self::TBL_BILLING_AGREEMENTS);
    }

    /**
     * @return string
     */
    public static function getBillingAgreementTransactionsTable()
    {
        return QUI::getDBTableName(self::TBL_BILLING_AGREEMENT_TRANSACTIONS);
    }

    /**
     * Get special exception message for different Billing Agreement confirmation constraint.
     *
     * @param string $constraintId
     * @return string
     */
    protected static function getBillingAgreementConfirmationConstraintExceptionMessage(string $constraintId)
    {
        switch ($constraintId) {
            case 'BuyerConsentNotSet':
            case 'PaymentPlanNotSet':
            case 'ShippingAddressNotSet':
            case 'BillingAddressDeleted':
            case 'InvalidPaymentPlan':
            case 'PaymentMethodDeleted':
            case 'PaymentMethodExpired':
            case 'PaymentMethodNotAllowed':
                $msg = QUI::getLocale()->get(
                    'quiqqer/payment-amazon',
                    'exception.BillingAgreements.constraint.' . $constraintId
                );
                break;

            case 'BuyerEqualsSeller':
            default:
                $msg = QUI::getLocale()->get(
                    'quiqqer/payment-amazon',
                    'exception.BillingAgreements.constraint.general'
                );
        }

        return $msg;
    }
}
