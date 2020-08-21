<?php

namespace QUI\ERP\Payments\Amazon\Recurring;

use QUI;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\Amazon\AmazonPayException;
use QUI\ERP\Payments\Amazon\Utils;
use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use QUI\ERP\Payments\Amazon\Payment as BasePayment;
use QUI\ERP\Payments\Amazon\AmazonPayException as AmazonException;
use QUI\ERP\Accounting\Invoice\Invoice;

/**
 * Class BillingAgreements
 *
 * Handler for Amazon Billing Agreement management
 */
class BillingAgreements
{
    const TBL_BILLING_AGREEMENTS             = 'amazon_billing_agreements';
    const TBL_BILLING_AGREEMENT_TRANSACTIONS = 'amazon_billing_agreement_transactions';

    const BILLING_AGREEMENT_STATE_ACTIVE = 'Active';
    const BILLING_AGREEMENT_STATE_OPEN   = 'Open';

    const BILLING_AGREEMENT_VALIDATION_SUCCESS = 'Success';
    const BILLING_AGREEMENT_VALIDATION_FAILURE = 'Failure';

    const TRANSACTION_STATE_COMPLETED = 'Completed';
    const TRANSACTION_STATE_DENIED    = 'Denied';

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

        \QUI\System\Log::writeRecursive($details);

        if (empty($details['BillingAgreementStatus'] ||
                  $details['BillingAgreementStatus'] !== self::BILLING_AGREEMENT_STATE_OPEN)) {
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

        \QUI\System\Log::writeRecursive($result);

        if (empty($result['ValidationResult']) || $result['ValidationResult'] !== self::BILLING_AGREEMENT_VALIDATION_SUCCESS) {
            $Order->addHistory(Utils::getHistoryText(
                'BillingAgreement.validate_billing_agreement_error'
            ));

            Utils::saveOrder($Order);

            throw new AmazonPayException([
                'quiqqer/payment-amazon',
                'exception.BillingAgreements.billing_agreement_not_validated'
            ]);
        }

        $Order->addHistory(Utils::getHistoryText(
            'BillingAgreement.validate_billing_agreement'
        ));

        $Order->setAttribute(Payment::ATTR_AMAZON_BILLING_AGREEMENT_VALIDATED, true);

        Utils::saveOrder($Order);
    }

    /**
     * Bills the balance for an agreement based on an Invoice
     *
     * @param Invoice $Invoice
     * @return void
     * @throws AmazonException
     * @throws \QUI\Exception
     */
    public static function billBillingAgreementBalance(Invoice $Invoice)
    {
        $billingAgreementId = $Invoice->getPaymentDataEntry(RecurringPayment::ATTR_AMAZON_BILLING_AGREEMENT_ID);

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

        $data = self::getBillingAgreementData($billingAgreementId);

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

//        try {
//            /** @var QUI\Locale $Locale */
//            $Locale      = $Invoice->getCustomer()->getLocale();
//            $InvoiceDate = new \DateTime($Invoice->getAttribute('date'));
//        } catch (\Exception $Exception) {
//            $Invoice->addHistory(
//                Utils::getHistoryText('invoice.error.general')
//            );
//
//            QUI\System\Log::writeException($Exception);
//            return;
//        }

        // Check if a Billing Agreement transaction matches the Invoice
        $unprocessedTransactions = self::getUnprocessedTransactions($billingAgreementId);
        $Invoice->calculatePayments();

        $invoiceAmount   = (float)$Invoice->getAttribute('toPay');
        $invoiceCurrency = $Invoice->getCurrency()->getCode();
        $Payment         = new RecurringPayment();

        foreach ($unprocessedTransactions as $transaction) {
            $amount   = (float)$transaction['amount']['value'];
            $currency = $transaction['amount']['currency'];

            if ($currency !== $invoiceCurrency) {
                continue;
            }

            if ($amount < $invoiceAmount) {
                continue;
            }

            // Transaction amount equals Invoice amount
            try {
                $AmazonTransactionDate = date_create($transaction['time_stamp']);

                $InvoiceTransaction = TransactionFactory::createPaymentTransaction(
                    $amount,
                    $Invoice->getCurrency(),
                    $Invoice->getHash(),
                    $Payment->getName(),
                    [
                        RecurringPayment::ATTR_AMAZON_BILLING_AGREEMENT_TRANSACTION_ID => $transaction['transaction_id']
                    ],
                    null,
                    $AmazonTransactionDate->getTimestamp(),
                    $Invoice->getGlobalProcessId()
                );

                $Invoice->addTransaction($InvoiceTransaction);

                QUI::getDataBase()->update(
                    self::getBillingAgreementTransactionsTable(),
                    [
                        'quiqqer_transaction_id'        => $InvoiceTransaction->getTxId(),
                        'quiqqer_transaction_completed' => 1
                    ],
                    [
                        'amazon_transaction_id' => $transaction['transaction_id']
                    ]
                );

                $Invoice->addHistory(
                    Utils::getHistoryText('invoice.add_amazon_transaction', [
                        'quiqqerTransactionId' => $InvoiceTransaction->getTxId(),
                        'amazonTransactionId'  => $transaction['transaction_id']
                    ])
                );
            } catch (\Exception $Exception) {
                QUI\System\Log::writeException($Exception);
            }

            break;
        }
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
            QUI\System\Log::addError(
                self::class.' :: searchUsers() -> '.$Exception->getMessage()
            );

            return [];
        }

        if ($countOnly) {
            return (int)current(current($result));
        }

        return $result;
    }

    /**
     * Get details of a Billing Agreement (Amazon data)
     *
     * @param string $billingAgreementId
     * @return array
     * @throws AmazonException
     */
    public static function getBillingAgreementDetails($billingAgreementId)
    {
        return self::amazonApiRequest(
            RecurringPayment::AMAZON_REQUEST_TYPE_GET_BILLING_AGREEMENT,
            [],
            [
                RecurringPayment::ATTR_AMAZON_BILLING_AGREEMENT_ID => $billingAgreementId
            ]
        );
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
     * Cancel a Billing Agreement
     *
     * @param int|string $billingAgreementId
     * @param string $reason (optional) - The reason why the billing agreement is being cancelled
     * @return void
     * @throws AmazonException
     * @throws QUI\Database\Exception
     */
    public static function cancelBillingAgreement($billingAgreementId, $reason = '')
    {
        $data = self::getBillingAgreementData($billingAgreementId);

        if (empty($data)) {
            return;
        }

        try {
            $Locale = new QUI\Locale();
            $Locale->setCurrent($data['customer']['lang']);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return;
        }

        if (empty($reason)) {
            $reason = $Locale->get(
                'quiqqer/payment-amazon',
                'recurring.billing_agreement.cancel.note',
                [
                    'url'             => Utils::getProjectUrl(),
                    'globalProcessId' => $data['globalProcessId']
                ]
            );
        }

        try {
            self::amazonApiRequest(
                RecurringPayment::AMAZON_REQUEST_TYPE_CANCEL_BILLING_AGREEMENT,
                [
                    'note' => $reason
                ],
                [
                    RecurringPayment::ATTR_AMAZON_BILLING_AGREEMENT_ID => $billingAgreementId
                ]
            );
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            throw new AmazonException(
                QUI::getLocale()->get(
                    'quiqqer/payment-amazon',
                    'exception.Recurring.cancel.error'
                )
            );
        }

        self::setBillingAgreementAsInactive($billingAgreementId);
    }

    /**
     * Set status of a BillingAgreement as inactive
     *
     * @param string $billingAgreementId
     * @return void
     */
    public static function setBillingAgreementAsInactive($billingAgreementId)
    {
        try {
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
     * Execute a Billing Agreement
     *
     * @param AbstractOrder $Order
     * @param string $agreementToken
     * @return void
     * @throws AmazonException
     */
    public static function executeBillingAgreement(AbstractOrder $Order, string $agreementToken)
    {
        try {
            $response = self::amazonApiRequest(
                RecurringPayment::AMAZON_REQUEST_TYPE_EXECUTE_BILLING_AGREEMENT,
                [],
                [
                    RecurringPayment::ATTR_AMAZON_BILLING_AGREEMENT_TOKEN => $agreementToken
                ]
            );
        } catch (AmazonException $Exception) {
            $Order->addHistory('Amazon :: Amazon API ERROR. Please check error logs.');
            Utils::saveOrder($Order);

            QUI\System\Log::writeException($Exception);

            throw new AmazonException(
                QUI::getLocale()->get(
                    'quiqqer/payment-amazon',
                    'exception.Recurring.order.error'
                )
            );
        }

        $Order->addHistory(Utils::getHistoryText('order.billing_agreement_accepted', [
            'agreementToken' => $agreementToken,
            'agreementId'    => $response['id']
        ]));

        $Order->setPaymentData(RecurringPayment::ATTR_AMAZON_BILLING_AGREEMENT_TOKEN, $agreementToken);
        $Order->setPaymentData(RecurringPayment::ATTR_AMAZON_BILLING_AGREEMENT_ID, $response['id']);
        $Order->setPaymentData(BasePayment::ATTR_AMAZON_PAYMENT_SUCCESSFUL, true);
        Utils::saveOrder($Order);

        // Save billing agreement reference in database
        try {
            QUI::getDataBase()->insert(
                self::getBillingAgreementsTable(),
                [
                    'amazon_agreement_id' => $Order->getPaymentDataEntry(RecurringPayment::ATTR_AMAZON_BILLING_AGREEMENT_ID),
                    'amazon_plan_id'      => $Order->getPaymentDataEntry(RecurringPayment::ATTR_AMAZON_BILLING_PLAN_ID),
                    'customer'            => json_encode($Order->getCustomer()->getAttributes()),
                    'global_process_id'   => $Order->getHash(),
                    'active'              => 1
                ]
            );
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            throw new AmazonException(
                QUI::getLocale()->get(
                    'quiqqer/payment-amazon',
                    'exception.Recurring.order.error'
                )
            );
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
                'payment_type' => RecurringPayment::class
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

                        // First: Process all failed transactions for Invoice
                        self::processDeniedTransactions($Invoice);

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
     * Processes all denied Amazon transactions for an Invoice and creates a corresponding ERP Transaction
     *
     * @param Invoice $Invoice
     * @return void
     */
    public static function processDeniedTransactions(Invoice $Invoice)
    {
        $billingAgreementId = $Invoice->getPaymentDataEntry(RecurringPayment::ATTR_AMAZON_BILLING_AGREEMENT_ID);

        if (empty($billingAgreementId)) {
            return;
        }

        $data = self::getBillingAgreementData($billingAgreementId);

        if (empty($data)) {
            return;
        }

        // Get all "Denied" Amazon transactions
        try {
            $unprocessedTransactions = self::getUnprocessedTransactions(
                $billingAgreementId,
                self::TRANSACTION_STATE_DENIED
            );
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return;
        }

        try {
            $Invoice->calculatePayments();

            $invoiceAmount   = (float)$Invoice->getAttribute('toPay');
            $invoiceCurrency = $Invoice->getCurrency()->getCode();
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return;
        }

        $Payment = new RecurringPayment();

        foreach ($unprocessedTransactions as $transaction) {
            $amount   = (float)$transaction['amount']['value'];
            $currency = $transaction['amount']['currency'];

            if ($currency !== $invoiceCurrency) {
                continue;
            }

            if ($amount < $invoiceAmount) {
                continue;
            }

            // Transaction amount equals Invoice amount
            try {
                $InvoiceTransaction = TransactionFactory::createPaymentTransaction(
                    $amount,
                    $Invoice->getCurrency(),
                    $Invoice->getHash(),
                    $Payment->getName(),
                    [],
                    null,
                    false,
                    $Invoice->getGlobalProcessId()
                );

                $InvoiceTransaction->changeStatus(TransactionHandler::STATUS_ERROR);

                $Invoice->addTransaction($InvoiceTransaction);

                QUI::getDataBase()->update(
                    self::getBillingAgreementTransactionsTable(),
                    [
                        'quiqqer_transaction_id'        => $InvoiceTransaction->getTxId(),
                        'quiqqer_transaction_completed' => 1
                    ],
                    [
                        'amazon_transaction_id' => $transaction['transaction_id']
                    ]
                );

                $Invoice->addHistory(
                    Utils::getHistoryText('invoice.add_amazon_transaction', [
                        'quiqqerTransactionId' => $InvoiceTransaction->getTxId(),
                        'amazonTransactionId'  => $transaction['id']
                    ])
                );
            } catch (\Exception $Exception) {
                QUI\System\Log::writeException($Exception);
            }
        }
    }

    /**
     * Refreshes transactions for a Billing Agreement
     *
     * @param string $billingAgreementId
     * @return void
     * @throws AmazonException
     * @throws QUI\Database\Exception
     * @throws \Exception
     */
    protected static function refreshTransactionList($billingAgreementId)
    {
        if (isset(self::$transactionsRefreshed[$billingAgreementId])) {
            return;
        }

        // Get global process id
        $data            = self::getBillingAgreementData($billingAgreementId);
        $globalProcessId = $data['globalProcessId'];

        // Determine start date
        $result = QUI::getDataBase()->fetch([
            'select' => ['amazon_transaction_date'],
            'from'   => self::getBillingAgreementTransactionsTable(),
            'where'  => [
                'amazon_agreement_id' => $billingAgreementId
            ],
            'order'  => [
                'field' => 'amazon_transaction_date',
                'sort'  => 'DESC'
            ],
            'limit'  => 1
        ]);

        if (empty($result)) {
            $Start = new \DateTime(date('Y').'-01-01 00:00:00'); // Beginning of current year
        } else {
            $Start = new \DateTime($result[0]['amazon_transaction_date']);
        }

        $End = new \DateTime(); // today

        // Determine existing transactions
        $result = QUI::getDataBase()->fetch([
            'select' => ['amazon_transaction_id', 'amazon_transaction_date'],
            'from'   => self::getBillingAgreementTransactionsTable(),
            'where'  => [
                'amazon_agreement_id' => $billingAgreementId
            ]
        ]);

        $existing = [];

        foreach ($result as $row) {
            $idHash            = md5($row['amazon_transaction_id'].$row['amazon_transaction_date']);
            $existing[$idHash] = true;
        }

        // Parse NEW transactions
        $transactions = self::getBillingAgreementTransactions($billingAgreementId, $Start, $End);

        foreach ($transactions as $transaction) {
            if (!isset($transaction['amount'])) {
                continue;
            }

            // Add warning if a transaction is unclaimed
            if ($transaction['status'] === 'Unclaimed') {
                QUI\System\Log::addWarning(
                    'Amazon Recurring Payments -> Some transactions for Billing Agreement '.$billingAgreementId
                    .' are marked as "Unclaimed" and cannot be processed for QUIQQER ERP Invoices. This most likely'
                    .' means that your Amazon merchant account does not support transactions'
                    .' in the transaction currency ('.$transaction['amount']['currency'].')!'
                );

                continue;
            }

            // Only collect transactions with status "Completed" or "Denied"
            if ($transaction['status'] !== self::TRANSACTION_STATE_COMPLETED
                && $transaction['status'] !== self::TRANSACTION_STATE_DENIED) {
                continue;
            }

            $TransactionTime = new \DateTime($transaction['time_stamp']);
            $transactionTime = $TransactionTime->format('Y-m-d H:i:s');

            $idHash = md5($transaction['transaction_id'].$transactionTime);

            if (isset($existing[$idHash])) {
                continue;
            }

            QUI::getDataBase()->insert(
                self::getBillingAgreementTransactionsTable(),
                [
                    'amazon_transaction_id'   => $transaction['transaction_id'],
                    'amazon_agreement_id'     => $billingAgreementId,
                    'amazon_transaction_data' => json_encode($transaction),
                    'amazon_transaction_date' => $transactionTime,
                    'global_process_id'       => $globalProcessId
                ]
            );
        }

        self::$transactionsRefreshed[$billingAgreementId] = true;
    }

    /**
     * Get all completed Billing Agreement transactions that are unprocessed by QUIQQER ERP
     *
     * @param string $billingAgreementId
     * @param string $status (optional) - Get transactions with this status [default: "Completed"]
     * @return array
     * @throws QUI\Database\Exception
     * @throws AmazonException
     * @throws \Exception
     */
    protected static function getUnprocessedTransactions(
        $billingAgreementId,
        $status = self::TRANSACTION_STATE_COMPLETED
    ) {
        $result = QUI::getDataBase()->fetch([
            'select' => ['amazon_transaction_data'],
            'from'   => self::getBillingAgreementTransactionsTable(),
            'where'  => [
                'amazon_agreement_id'    => $billingAgreementId,
                'quiqqer_transaction_id' => null
            ]
        ]);

        // Try to refresh list if no unprocessed transactions found
        if (empty($result)) {
            self::refreshTransactionList($billingAgreementId);

            $result = QUI::getDataBase()->fetch([
                'select' => ['amazon_transaction_data'],
                'from'   => self::getBillingAgreementTransactionsTable(),
                'where'  => [
                    'amazon_agreement_id'    => $billingAgreementId,
                    'quiqqer_transaction_id' => null
                ]
            ]);
        }

        $transactions = [];

        foreach ($result as $row) {
            $t = json_decode($row['amazon_transaction_data'], true);

            if ($t['status'] !== $status) {
                continue;
            }

            $transactions[] = $t;
        }

        return $transactions;
    }

    /**
     * Make a Amazon REST API request
     *
     * @param string $request - Request type (see self::AMAZON_REQUEST_TYPE_*)
     * @param array $body - Request data
     * @param AbstractOrder|Transaction|array $TransactionObj - Object that contains necessary request data
     * ($Order has to have the required paymentData attributes for the given $request value!)
     * @return array|false - Response body or false on error
     *
     * @throws AmazonException
     */
    protected static function amazonApiRequest($request, $body, $TransactionObj)
    {
        if (is_null(self::$Payment)) {
            self::$Payment = new QUI\ERP\Payments\Amazon\Payment();
        }

        return self::$Payment->amazonApiRequest($request, $body, $TransactionObj);
    }

    /**
     * Get available data by Billing Agreement ID (QUIQQER data)
     *
     * @param string $billingAgreementId - Amazon Billing Agreement ID
     * @return array|false
     */
    public static function getBillingAgreementData($billingAgreementId)
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
            'active'          => !empty($data['active']) ? true : false,
            'globalProcessId' => $data['global_process_id'],
            'customer'        => json_decode($data['customer'], true),
        ];
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
