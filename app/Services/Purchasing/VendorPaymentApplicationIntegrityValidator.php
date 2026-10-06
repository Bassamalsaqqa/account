<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Models\Company;
use App\Models\PostingBatch;
use App\Models\VendorPaymentApplicationEvent;

final class VendorPaymentApplicationIntegrityValidator
{
    public function __construct(private readonly VendorPaymentHistoryCommands $commands) {}

    public function validate(VendorPaymentApplicationEvent $event, ?PostingBatch $pendingBatch = null, bool $completing = false, ?string $expectedReversalDate = null): void
    {
        if (! $completing && $event->applied_at === null) {
            throw new ImmutableRecordException('Incomplete advance application.');
        }
        $payment = $event->vendorPayment()->firstOrFail();
        if (! $completing && (bool) $payment->is_reversed !== ($event->reversed_at !== null)) {
            throw new ImmutableRecordException('Application reversal must belong to the complete parent Payment reversal.');
        }
        $command = $this->commands->application($event);
        $batchId = $completing ? $pendingBatch?->id : $event->posting_batch_id;
        if ($command === null) {
            if ($batchId !== null) {
                throw new ImmutableRecordException('Zero-effect application has accounting history.');
            }
        } else {
            $batch = PostingBatch::where('company_id', $event->company_id)->findOrFail($batchId);
            $this->commands->assertBatch($command, $batch);
            if ($event->reversed_at !== null) {
                if ($event->reversal_posting_batch_id === null || $event->reversed_by === null) {
                    throw new ImmutableRecordException('Incomplete application reversal.');
                }
                $this->commands->assertReversal($batch, (int) $event->reversal_posting_batch_id, (int) $event->reversed_by,
                    $expectedReversalDate ?? $payment->reversalPostingBatch()->firstOrFail()->posting_date->toDateString());
            } elseif ($batch->status !== 'posted' || $batch->reversed_by_batch_id !== null) {
                throw new ImmutableRecordException('Unexpected application accounting reversal.');
            }
        }
        if ($event->reversed_at !== null) {
            $parentReversal = PostingBatch::where('company_id', $event->company_id)->findOrFail($payment->reversal_posting_batch_id);
            if ($parentReversal->posting_date->toDateString() < $event->application_date->toDateString()) {
                throw new ImmutableRecordException('Vendor Payment reversal predates its dependent application.');
            }
        }
        if ($event->reversed_at !== null && $expectedReversalDate !== null
            && $event->reversed_at->copy()->setTimezone(Company::findOrFail($event->company_id)->timezone)->toDateString() !== $expectedReversalDate) {
            throw new ImmutableRecordException('Application reversal timestamp disagrees with its business date.');
        }
        if (($event->reversed_at === null && ($event->reversed_by !== null || $event->reversal_posting_batch_id !== null))
            || ($event->reversed_at !== null && ($event->reversed_by === null || ($command === null && $event->reversal_posting_batch_id !== null)))) {
            throw new ImmutableRecordException('Application reversal lifecycle mismatch.');
        }
    }
}
