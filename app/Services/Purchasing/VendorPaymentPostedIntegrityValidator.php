<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Models\PostingBatch;
use App\Models\VendorPayment;

final class VendorPaymentPostedIntegrityValidator
{
    public function __construct(private readonly VendorPaymentHistoryCommands $commands) {}

    public function validate(VendorPayment $payment, ?PostingBatch $pendingBatch = null, bool $includeApplications = true): void
    {
        $batch = $pendingBatch === null ? $payment->postingBatch()->first() : PostingBatch::where('company_id', $payment->company_id)->findOrFail($pendingBatch->id);
        if ($batch === null || ($pendingBatch === null && ($payment->posted_at === null || (int) $payment->posted_by !== (int) $payment->created_by))) {
            throw new ImmutableRecordException('Incomplete canonical Vendor Payment.');
        }
        $this->commands->assertBatch($this->commands->payment($payment), $batch);
        if ($payment->is_reversed) {
            if ($payment->reversed_at === null || $payment->reversed_by === null || $payment->reversal_posting_batch_id === null) {
                throw new ImmutableRecordException('Incomplete Vendor Payment reversal.');
            }
            $this->commands->assertReversal($batch, (int) $payment->reversal_posting_batch_id, (int) $payment->reversed_by);
        } elseif ($batch->status !== 'posted' || $batch->reversed_by_batch_id !== null || $payment->reversed_at !== null || $payment->reversed_by !== null || $payment->reversal_posting_batch_id !== null) {
            throw new ImmutableRecordException('Unexpected Vendor Payment reversal history.');
        }
        if ($includeApplications) {
            foreach ($payment->applicationEvents()->orderBy('id')->get() as $event) {
                app(VendorPaymentApplicationIntegrityValidator::class)->validate($event);
                if ($payment->is_reversed && $event->reversed_at === null) {
                    throw new ImmutableRecordException('Dependent application was not reversed.');
                }
            }
        }
    }
}
