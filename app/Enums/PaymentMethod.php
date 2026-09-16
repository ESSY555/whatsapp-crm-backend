<?php

namespace App\Enums;

/**
 * How a business paid for a plan. Only bank transfer + uploaded receipt
 * exists today; a processor (Paystack etc.) adds a case here and creates
 * an already-approved PaymentRequest from its webhook — no other rework.
 */
enum PaymentMethod: string
{
    case ManualReceipt = 'manual_receipt';

    public function label(): string
    {
        return match ($this) {
            self::ManualReceipt => 'Bank transfer (receipt upload)',
        };
    }
}
