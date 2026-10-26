<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Message;
use App\Models\PaymentSetting;
use App\Models\TopupRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Notifications\TopupRequestReviewed;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class WalletService
{
    public const RECEIPT_DISK = 'local';

    public function __construct(
        private NotificationService $notifications,
        private AuditLogger $audit,
    ) {
    }

    public function receiptDisk(): Filesystem
    {
        return Storage::disk(self::RECEIPT_DISK);
    }

    /**
     * Retrieve or initialize the wallet for a business.
     */
    public function getOrCreateWallet(Business $business): Wallet
    {
        return Wallet::withoutGlobalScopes()
            ->firstOrCreate(
                ['business_id' => $business->id],
                ['balance' => 0.00, 'currency' => 'NGN', 'low_balance_alerted' => false]
            );
    }

    /**
     * Credit the business wallet with locking and balance update.
     */
    public function credit(
        Business $business,
        float $amount,
        string $description,
        ?string $reference = null,
        ?array $metadata = null
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Credit amount must be greater than zero.');
        }

        return DB::transaction(function () use ($business, $amount, $description, $reference, $metadata) {
            /** @var Wallet $wallet */
            $wallet = Wallet::withoutGlobalScopes()
                ->where('business_id', $business->id)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                $wallet = Wallet::withoutGlobalScopes()->create([
                    'business_id' => $business->id,
                    'balance' => 0.00,
                    'currency' => 'NGN',
                    'low_balance_alerted' => false,
                ]);
                $wallet = Wallet::withoutGlobalScopes()->whereKey($wallet->id)->lockForUpdate()->first();
            }

            $balanceBefore = (float) $wallet->balance;
            // Use bcmath for addition
            $newBalance = (float) bcadd((string) $balanceBefore, (string) $amount, 2);

            $setting = PaymentSetting::current();
            $threshold = (float) $setting->low_balance_threshold;

            $wallet->balance = $newBalance;
            // Reset low balance alert flag if balance is lifted back above threshold
            if ($newBalance >= $threshold) {
                $wallet->low_balance_alerted = false;
            }
            $wallet->save();

            return WalletTransaction::withoutGlobalScopes()->create([
                'wallet_id' => $wallet->id,
                'business_id' => $business->id,
                'type' => 'credit',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $newBalance,
                'price_per_message' => null,
                'reference' => $reference,
                'description' => $description,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Debit the business wallet with locking and balance check.
     */
    public function debit(
        Business $business,
        float $amount,
        string $description,
        ?float $pricePerMessage = null,
        ?string $reference = null,
        ?array $metadata = null
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Debit amount must be greater than zero.');
        }

        return DB::transaction(function () use ($business, $amount, $description, $pricePerMessage, $reference, $metadata) {
            /** @var Wallet $wallet */
            $wallet = Wallet::withoutGlobalScopes()
                ->where('business_id', $business->id)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                $wallet = Wallet::withoutGlobalScopes()->create([
                    'business_id' => $business->id,
                    'balance' => 0.00,
                    'currency' => 'NGN',
                    'low_balance_alerted' => false,
                ]);
                $wallet = Wallet::withoutGlobalScopes()->whereKey($wallet->id)->lockForUpdate()->first();
            }

            $balanceBefore = (float) $wallet->balance;
            if ($balanceBefore < $amount) {
                throw ValidationException::withMessages([
                    'wallet' => [
                        sprintf(
                            'Insufficient wallet balance. This action requires ₦%s, but your balance is ₦%s. Please top up your wallet.',
                            number_format($amount, 2),
                            number_format($balanceBefore, 2)
                        ),
                    ],
                ]);
            }

            // Use bcmath for subtraction
            $newBalance = (float) bcsub((string) $balanceBefore, (string) $amount, 2);

            $setting = PaymentSetting::current();
            $threshold = (float) $setting->low_balance_threshold;

            $shouldAlert = false;
            // Trip low-balance alert once when crossing from >= threshold to < threshold
            if ($balanceBefore >= $threshold && $newBalance < $threshold && !$wallet->low_balance_alerted) {
                $wallet->low_balance_alerted = true;
                $shouldAlert = true;
            }

            $wallet->balance = $newBalance;
            $wallet->save();

            $transaction = WalletTransaction::withoutGlobalScopes()->create([
                'wallet_id' => $wallet->id,
                'business_id' => $business->id,
                'type' => 'debit',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $newBalance,
                'price_per_message' => $pricePerMessage,
                'reference' => $reference,
                'description' => $description,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);

            if ($shouldAlert) {
                TenantManager::runAs($business->id, fn () => $this->notifications->lowWalletBalance($wallet, $threshold));
            }

            return $transaction;
        });
    }

    /**
     * Idempotently refund a failed message billed from the wallet.
     * Guaranteed to refund at most once per message.
     */
    public function refundMessage(Message $message, string $reason): ?WalletTransaction
    {
        // Only messages billed from the wallet and not already refunded can be refunded
        if ($message->billing_source !== 'wallet' || $message->unit_price === null || $message->unit_price <= 0) {
            return null;
        }

        // Atomically claim the refund on the message record
        $affected = Message::withoutGlobalScopes()
            ->whereKey($message->id)
            ->whereNull('refunded_at')
            ->update(['refunded_at' => now()]);

        if ($affected === 0) {
            return null; // Already refunded or raced by another worker
        }

        $message->refunded_at = now();

        $business = Business::query()->find($message->business_id);
        if (!$business) {
            return null;
        }

        $refundAmount = (float) $message->unit_price;
        $description = sprintf('Delivery failure refund for message #%d (%s)', $message->id, rtrim($reason, '.'));
        $reference = "refund_msg_{$message->id}";

        return $this->credit($business, $refundAmount, $description, $reference, [
            'message_id' => $message->id,
            'reason' => $reason,
        ]);
    }

    /**
     * Submit a top-up request with receipt and claimed amount.
     */
    public function submitTopup(
        Business $business,
        User $user,
        UploadedFile $receipt,
        float $amount,
        ?string $reference = null,
        ?string $note = null
    ): TopupRequest {
        $setting = PaymentSetting::current();
        $minAmount = (float) $setting->min_topup_amount;

        if ($amount < $minAmount) {
            throw ValidationException::withMessages([
                'amount' => [
                    sprintf('The minimum top-up amount is ₦%s.', number_format($minAmount, 2)),
                ],
            ]);
        }

        $path = $receipt->store("receipts/wallet/{$business->id}", self::RECEIPT_DISK);

        return TenantManager::runAs($business->id, fn () => TopupRequest::create([
            'submitted_by' => $user->id,
            'amount' => $amount,
            'currency' => 'NGN',
            'receipt_path' => $path,
            'receipt_name' => $receipt->getClientOriginalName(),
            'receipt_mime' => $receipt->getClientMimeType(),
            'reference' => $reference,
            'note' => $note,
            'status' => 'pending',
        ]));
    }

    /**
     * Approve a top-up request: atomically credits tenant wallet and updates request status.
     * The admin cannot edit the amount.
     */
    public function approveTopup(TopupRequest $request, User $admin): TopupRequest
    {
        return DB::transaction(function () use ($request, $admin) {
            /** @var TopupRequest $record */
            $record = TopupRequest::withoutGlobalScopes()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (!$record->isPending()) {
                throw ValidationException::withMessages([
                    'status' => ['This top-up request has already been reviewed.'],
                ]);
            }

            $business = Business::query()->findOrFail($record->business_id);
            $amount = (float) $record->amount;

            // Credit tenant wallet with exact submitted amount
            $this->credit(
                $business,
                $amount,
                sprintf('Wallet top-up approved (Ref: %s)', $record->reference ?: "#{$record->id}"),
                "topup_{$record->id}",
                ['topup_request_id' => $record->id, 'reviewed_by' => $admin->id]
            );

            $record->update([
                'status' => 'approved',
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            TenantManager::runAs($business->id, function () use ($record, $business) {
                $this->notifications->walletTopupApproved($record);
                $this->audit->log('topup_request.approved', $record, null, null, [
                    'amount' => (float) $record->amount,
                ]);

                // Notify owner by email
                $owner = $business->users()->wherePivot('role', 'owner')->first();
                if ($owner) {
                    $owner->notify(new TopupRequestReviewed($record));
                }
            });

            return $record->fresh(['submitter:id,name,email', 'reviewer:id,name']);
        });
    }

    /**
     * Reject a top-up request with mandatory reason.
     */
    public function rejectTopup(TopupRequest $request, User $admin, string $reason): TopupRequest
    {
        $reason = trim($reason);
        if (strlen($reason) < 5) {
            throw ValidationException::withMessages([
                'reason' => ['A rejection reason of at least 5 characters is required.'],
            ]);
        }

        return DB::transaction(function () use ($request, $admin, $reason) {
            /** @var TopupRequest $record */
            $record = TopupRequest::withoutGlobalScopes()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (!$record->isPending()) {
                throw ValidationException::withMessages([
                    'status' => ['This top-up request has already been reviewed.'],
                ]);
            }

            $business = Business::query()->findOrFail($record->business_id);

            $record->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            TenantManager::runAs($business->id, function () use ($record, $business, $reason) {
                $this->notifications->walletTopupRejected($record);
                $this->audit->log('topup_request.rejected', $record, null, null, [
                    'reason' => $reason,
                ]);

                // Notify owner by email
                $owner = $business->users()->wherePivot('role', 'owner')->first();
                if ($owner) {
                    $owner->notify(new TopupRequestReviewed($record));
                }
            });

            return $record->fresh(['submitter:id,name,email', 'reviewer:id,name']);
        });
    }
}
