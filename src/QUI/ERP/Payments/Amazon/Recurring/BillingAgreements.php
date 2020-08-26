<?php

namespace QUI\ERP\Payments\Amazon\Recurring;

use QUI;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\Amazon\AmazonPayException;
use QUI\ERP\Payments\Amazon\Utils;
use QUI\Utils\Security\Orthos;
use QUI\ERP\Payments\Amazon\Payment as BasePayment;
use QUI\ERP\Payments\Amazon\AmazonPayException as AmazonException;
use QUI\ERP\Accounting\Invoice\Invoice;
use QUI\ERP\Accounting\Payments\Transactions\Factory as TransactionFactory;
use QUI\ERP\Accounting\Invoice\Handler as InvoiceHandler;
use QUI\ERP\Accounting\Payments\Payments;

/**
 * Class BillingAgreements
 *
 * Handler for Amazon Billing Agreement management
 */
class BillingAgreements
{
    const TBL_BILLING_AGREEMENTS             = 'amazon_billing_agreements';
    const TBL_BILLING_AGREEMENT_TRANSACTIONS = 'amazon_billing_agreement_transactions';

    const BILLING_AGREEMENT_STATE_ACTIVE       = 'Active';
    const BILLING_AGREEMENT_STATE_OPEN         = 'Open';
    const BILLING_AGREEMENT_VALIDATION_SUCCESS = 'Success';

    const TRANSACTION_STATE_COMPLETED = 'Completed';
    const TRANSACTION_STATE_DENIED    = 'Denied';

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
            'seller_note'                 => QUI::getLocale()->get(
                'quiqqer/payment-amazon',
                'recurring.BillingAgreement.seller_note',
                [
                    'orderId' => $Order->getId(),
                    'url'     => Utils::getProjectUrl()
                ]
            )
        ]);

        $Order->setAttribute(Payment::ATTR_AMAZON_BILLING_AGREEMENT_ID, $billingAgreementId);
        $Order->setPaymentData(Payment::ATTR_AMAZON_BILLING_AGREEMENT_ID, $billingAgreementId);
        $Order->addHistory(Utils::getHistoryText('BillingAgreement.set_details'));
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
        if ($Order->getAttribute(Payment::ATTR_AMAZON_BILLING_AGREEMENT_CONFIRMED)) {
            return;
        }

        $AmazonPay          = BasePayment::getAmazonPayClient();
        $billingAgreementId = $Order->getAttribute(Payment::ATTR_AMAZON_BILLING_AGREEMENT_ID);

        $AmazonPay->confirmBillingAgreement([
            'amazon_billing_agreement_id' => $billingAgreementId,
            'success_url'                 => Utils::getSuccessUrl($Order),
            'failure_url'                 => Utils::getFailureUrl($Order)
        ]);

        // Check if BillingAgreement is in OPEN state
        $Response = $AmazonPay->getBillingAgreementDetails([
            'amazon_billing_agreement_id' => $billingAgreementId
        ]);

        $details = $Response->toArray();
        $details = $details['GetBillingAgreementDetailsResult']['BillingAgreementDetails'];

        if (empty($details['BillingAgreementStatus']['State'] ||
                  $details['BillingAgreementStatus']['State'] !== self::BILLING_AGREEMENT_STATE_OPEN)) {
            $Order->addHistory(Utils::getHistoryText(
                'BillingAgreement.confirm_billing_agreement_error'
            ));

            Utils::saveOrder($Order);

            throw new AmazonPayException([
                'quiqqer/payment-amazon',
                'exception.BillingAgreements.billing_agreement_not_confirmed'
            ]);
        }

        $Order->addHistory(Utils::getHistoryText(
            'BillingAgreement.confirm_billing_agreement',
            [
                'billingAgreementId' => $billingAgreementId
            ]
        ));

        $Order->setAttribute(Payment::ATTR_AMAZON_BILLING_AGREEMENT_CONFIRMED, true);

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
        if ($Order->getAttribute(Payment::ATTR_AMAZON_BILLING_AGREEMENT_VALIDATED)) {
            return;
        }

        $AmazonPay = BasePayment::getAmazonPayClient();

        $Response = $AmazonPay->validateBillingAgreement([
            'amazon_billing_agreement_id' => $Order->getAttribute(Payment::ATTR_AMAZON_BILLING_AGREEMENT_ID)
        ]);

        $result = $Response->toArray();
        $result = $result['ValidateBillingAgreementResult'];

        if (empty($result['ValidationResult']) || $result['ValidationResult'] !== self::BILLING_AGREEMENT_VALIDATION_SUCCESS) {
            $Order->addHistory(Utils::getHistoryText(
                'BillingAgreement.validate_billing_agreement_error'
            ));

            Utils::saveOrder($Order);

            throw new AmazonPayException(
                [
                    'quiqqer/payment-amazon',
                    'exception.BillingAgreements.billing_agreement_not_validated'
                ],
                self::EXCEPTION_CODE_BILLING_AGREEMENT_VALIDATION_ERROR
            );
        }

        $Order->addHistory(Utils::getHistoryText(
            'BillingAgreement.validate_billing_agreement'
        ));

        $Order->setAttribute(Payment::ATTR_AMAZON_BILLING_AGREEMENT_VALIDATED, true);

        Utils::saveOrder($Order);

        // Save billing agreement data in database
        $Customer = $Order->getCustomer();

        QUI::getDataBase()->insert(
            self::getBillingAgreementsTable(),
            [
                'amazon_agreement_id' => $Order->getAttribute(Payment::ATTR_AMAZON_BILLING_AGREEMENT_ID),
                'customer'            => \json_encode($Customer->getAttributes()),
                'global_process_id'   => $Order->getHash(),
                'active'              => 1
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

        // Check if a Billing Agreement transaction matches the Invoice
        $transactionData = self::getBillingAgreementTransactionData($billingAgreementId, $Invoice->getCleanId());

        // If no transaction data found -> create DB entry
        QUI::getDataBase()->insert(
            self::getBillingAgreementTransactionsTable(),
            [
                'invoice_id'          => $Invoice->getCleanId(),
                'amazon_agreement_id' => $billingAgreementId,
                'global_process_id'   => $Invoice->getGlobalProcessId()
            ]
        );

        $invoiceAmount   = Utils::getFormattedPriceByInvoice($Invoice);
        $invoiceCurrency = $Invoice->getCurrency()->getCode();
        $Payment         = new Payment();
        $AmazonPay       = BasePayment::getAmazonPayClient();

        $Request = $AmazonPay->authorizeOnBillingAgreement([
            'amazon_billing_agreement_id' => $billingAgreementId,
            'authorization_reference_id'  => $Invoice->getHash(),
            'authorization_amount'        => $invoiceAmount,
            'currency_code'               => $invoiceCurrency,
            'capture_now'                 => true,   // immediately capture amount
            'seller_authorization_note'   => QUI::getLocale()->get(
                'quiqqer/payment-amazon',
                'recurring.BillingAgreement.seller_authorization_note',
                [
                    'url'       => Utils::getProjectUrl(),
                    'invoiceId' => $Invoice->getId()
                ]
            )
        ]);

        $data = $Request->toArray();

        \QUI\System\Log::writeRecursive($data);

        $data = $data['AuthorizeOnBillingAgreementResult']['AuthorizationDetails'];

        $capturedAmount = $data['CapturedAmount'];

        $captureAttempts = $transactionData ? $transactionData['capture_attempts'] : 0;
        $captureAttempts++;

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
                        'attempts'           => $captureAttempts
                    ])
                );
            }

            QUI::getDataBase()->update(
                self::getBillingAgreementTransactionsTable(),
                [
                    'capture_attempts' => $captureAttempts
                ],
                [
                    'invoice_id'          => $Invoice->getCleanId(),
                    'amazon_agreement_id' => $billingAgreementId
                ]
            );

            return;
        }

        // Transaction amount equals Invoice amount
        try {
            $InvoiceTransaction = TransactionFactory::createPaymentTransaction(
                $invoiceAmount,
                $invoiceCurrency,
                $Invoice->getHash(),
                $Payment->getName(),
                [
                    self::ATTR_BILLING_AGREEMENT_AUTHORIZATION_ID => $data['AmazonAuthorizationId']
                ],
                null,
                null,
                $Invoice->getGlobalProcessId()
            );

            $Invoice->addTransaction($InvoiceTransaction);

            $TransactionDate = \date_create($data['CreationTimestamp']);

            QUI::getDataBase()->update(
                self::getBillingAgreementTransactionsTable(),
                [
                    'quiqqer_transaction_id'        => $InvoiceTransaction->getTxId(),
                    'quiqqer_transaction_completed' => 1,
                    'amazon_authorization_id'       => $data['AmazonAuthorizationId'],
                    'amazon_transaction_data'       => \json_encode($Request->toArray()),
                    'amazon_transaction_date'       => $TransactionDate->format('Y-m-d H:i:s'),
                    'capture_attempts'              => $captureAttempts,
                ],
                [
                    'invoice_id'          => $Invoice->getCleanId(),
                    'amazon_agreement_id' => $billingAgreementId
                ]
            );

            $Invoice->addHistory(
                Utils::getHistoryText('invoice.add_amazon_transaction', [
                    'quiqqerTransactionId'  => $InvoiceTransaction->getTxId(),
                    'amazonAuthorizationId' => $data['AmazonAuthorizationId'],
                    'billingAgreementId'    => $billingAgreementId
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
     * Get data of all Billing Agreements (QUIQQER data only; no Amazon query performed!)
     *
     * @param array $searchParams
     * @param bool $countOnly (optional) - Return count of all results
     * @return array|int
     */
    public static function getBillingAgreementList($searchParams, $countOnly = false)
    {
        $Grid       = new QUI\Utils\Grid($searchParams);
        $gridParams = $Grid->parseDBParams($searchParams);

        $binds = [];
        $where = [];

        if ($countOnly) {
            $sql = "SELECT COUNT(amazon_agreement_id)";
        } else {
            $sql = "SELECT *";
        }

        $sql .= " FROM `".self::getBillingAgreementsTable()."`";

        if (!empty($searchParams['search'])) {
            $where[] = '`global_process_id` LIKE :search';

            $binds['search'] = [
                'value' => '%'.$searchParams['search'].'%',
                'type'  => \PDO::PARAM_STR
            ];
        }

        // build WHERE query string
        if (!empty($where)) {
            $sql .= " WHERE ".implode(" AND ", $where);
        }

        // ORDER
        if (!empty($searchParams['sortOn'])
        ) {
            $sortOn = Orthos::clear($searchParams['sortOn']);
            $order  = "ORDER BY ".$sortOn;

            if (isset($searchParams['sortBy']) &&
                !empty($searchParams['sortBy'])
            ) {
                $order .= " ".Orthos::clear($searchParams['sortBy']);
            } else {
                $order .= " ASC";
            }

            $sql .= " ".$order;
        }

        // LIMIT
        if (!empty($gridParams['limit'])
            && !$countOnly
        ) {
            $sql .= " LIMIT ".$gridParams['limit'];
        } else {
            if (!$countOnly) {
                $sql .= " LIMIT ".(int)20;
            }
        }

        $Stmt = QUI::getPDO()->prepare($sql);

        // bind search values
        foreach ($binds as $var => $bind) {
            $Stmt->bindValue(':'.$var, $bind['value'], $bind['type']);
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
                'from'  => self::getBillingAgreementTransactionsTable(),
                'where' => [
                    'amazon_agreement_id' => $billingAgreementId,
                    'invoice_id'          => $invoiceId
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
     * Get transaction list for a Billing Agreement
     *
     * @param string $billingAgreementId
     * @param \DateTime $Start (optional)
     * @param \DateTime $End (optional)
     * @return array
     * @throws AmazonException
     * @throws \Exception
     */
    public static function getBillingAgreementTransactions(
        $billingAgreementId,
        \DateTime $Start = null,
        \DateTime $End = null
    ) {
        $data = [
            RecurringPayment::ATTR_AMAZON_BILLING_AGREEMENT_ID => $billingAgreementId
        ];

        if (is_null($Start)) {
            $Start = new \DateTime(date('Y-m').'-01 00:00:00');
        }

        if (is_null($End)) {
            $End = clone $Start;
            $End->add(new \DateInterval('P1M')); // Start + 1 month as default time period
        }

        $data['start_date'] = $Start->format('Y-m-d');

        if ($End > $Start && $Start->format('Y-m-d') !== $End->format('Y-m-d')) {
            $data['end_date'] = $End->format('Y-m-d');
        }

        $result = self::amazonApiRequest(
            RecurringPayment::AMAZON_REQUEST_TYPE_GET_BILLING_AGREEMENT_TRANSACTIONS,
            [],
            $data
        );

        return $result['agreement_transaction_list'];
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
            'where'  => [
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
            'where'  => [
                'paid_status'    => 0,
                'type'           => InvoiceHandler::TYPE_INVOICE,
                'payment_method' => [
                    'type'  => 'IN',
                    'value' => $paymentTypeIds
                ]
            ],
            'order'  => 'date ASC'
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
                'from'   => self::getBillingAgreementsTable(),
                'where'  => [
                    'global_process_id' => [
                        'type'  => 'IN',
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
                'from'  => self::getBillingAgreementsTable(),
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
            'active'          => !empty($data['active']),
            'globalProcessId' => $data['global_process_id'],
            'customer'        => json_decode($data['customer'], true)
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
}
