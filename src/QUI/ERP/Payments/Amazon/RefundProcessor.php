<?php

namespace QUI\ERP\Payments\Amazon;

use Exception;
use QUI;
use QUI\ERP\Accounting\Payments\Transactions\Handler as TransactionHandler;
use QUI\ERP\Payments\Amazon\Payment as AmazonPayment;

/**
 * Class RefundProcessor
 *
 * Processes open refund transactions
 */
class RefundProcessor
{
    /**
     * DB tables
     */
    const TBL_REFUND_TRANSACTIONS = 'amazon_refund_transactions';

    /**
     * Processes all open refund transactions
     *
     * @return void
     */
    public static function processOpenRefundTransactions(): void
    {
        try {
            $result = QUI::getDataBase()->fetch([
                'from' => self::getRefundTransactionsTable()
            ]);
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return;
        }

        foreach ($result as $row) {
            try {
                self::checkRefund($row['tx_id'], $row['amazon_refund_id']);
            } catch (Exception $Exception) {
                QUI\System\Log::writeException($Exception);
            }
        }
    }

    /**
     * Checks the refund status for a transaction
     *
     * @param string $txId
     * @param string $amazonRefundId
     * @return void
     *
     * @throws QUI\Exception
     * @throws Exception
     */
    protected static function checkRefund(string $txId, string $amazonRefundId): void
    {
        $AmazonPay = Payment::getAmazonPayClient();
        $Response = $AmazonPay->getRefundDetails([
            'amazon_refund_id' => $amazonRefundId
        ]);

        $data = $Response->toArray();
        $data = $data['GetRefundDetailsResult']['RefundDetails'];

        $RefundTransaction = TransactionHandler::getInstance()->get($txId);

        $removeEntry = function () use ($txId) {
            // Remove entry from db
            QUI::getDataBase()->delete(
                self::getRefundTransactionsTable(),
                [
                    'tx_id' => $txId
                ]
            );
        };

        switch ($data['RefundStatus']['State']) {
            case 'Completed':
                try {
                    $AmazonPayment = new AmazonPayment();
                    $AmazonPayment->finalizeRefund($RefundTransaction, $data);
                } catch (Exception $Exception) {
                    QUI\System\Log::writeException($Exception);
                }

                $removeEntry();
                break;

            case 'Pending':
                // If the proces is still pending -> do nothing and check again on next execution
                break;

            case 'Declined':
                $reason = $data['RefundStatus']['ReasonCode'];
                $Process = new QUI\ERP\Process($RefundTransaction->getGlobalProcessId());

                $Process->addHistory(
                    'Amazon Pay :: Refund operation failed with state "' . $data['RefundStatus']['State'] . '".'
                    . ' ReasonCode: "' . $reason . '".'
                    . ' Transaction #' . $RefundTransaction->getTxId()
                );

                $RefundTransaction->error();

                $removeEntry();
                break;
        }
    }

    /**
     * @return string
     */
    public static function getRefundTransactionsTable(): string
    {
        return QUI::getDBTableName(self::TBL_REFUND_TRANSACTIONS);
    }
}
