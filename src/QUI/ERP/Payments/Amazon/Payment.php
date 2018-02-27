<?php

/**
 * This file contains QUI\ERP\Payments\Example\Payment
 */

namespace QUI\ERP\Payments\Amazon;

use QUI;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Accounting\Payments\Transactions\Factory as Transactions;

/**
 * Class Payment
 * - This class is your main API point for your payment type
 *
 * @package QUI\ERP\Payments\Example\Example
 */
class Payment extends QUI\ERP\Accounting\Payments\Api\AbstractPayment
{
    /**
     * @return string
     */
    public function getTitle()
    {
        // TODO: Implement getTitle() method.
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        // TODO: Implement getDescription() method.
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
}
