<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\DocumentSequence;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DocumentSequenceService
{
    public const array DEFAULT_PREFIXES = [
        DocumentSequence::TYPE_QUOTATION => 'QTN',
        DocumentSequence::TYPE_SALES_INVOICE => 'INV',
        DocumentSequence::TYPE_SALES_RETURN => 'RET',
        DocumentSequence::TYPE_CUSTOMER_PAYMENT => 'RCT',
    ];

    /**
     * Atomically generate and advance the next document number with row-level locking.
     * Must be called inside an active database transaction.
     */
    public function generateNextNumber(int $companyId, string $documentType, ?int $year = null): string
    {
        if (DB::transactionLevel() <= 0) {
            throw new RuntimeException('generateNextNumber must be called within an active database transaction.');
        }

        $effectiveYear = $year ?? (int) Carbon::now()->year;

        // Check if there is a 'never' reset sequence first
        /** @var DocumentSequence|null $neverSequence */
        $neverSequence = DocumentSequence::where('company_id', $companyId)
            ->where('document_type', $documentType)
            ->where('reset_policy', 'never')
            ->lockForUpdate()
            ->first();

        if ($neverSequence !== null) {
            $currentNumber = $neverSequence->next_number;
            $padded = str_pad((string) $currentNumber, (int) $neverSequence->padding, '0', STR_PAD_LEFT);
            $formatted = "{$neverSequence->prefix}-{$padded}";

            $neverSequence->next_number = $currentNumber + 1;
            $neverSequence->save();

            return $formatted;
        }

        // Otherwise, look for the sequence for this effectiveYear
        /** @var DocumentSequence|null $sequence */
        $sequence = DocumentSequence::where('company_id', $companyId)
            ->where('document_type', $documentType)
            ->where('year', $effectiveYear)
            ->lockForUpdate()
            ->first();

        if ($sequence === null) {
            // Find existing sequence from previous year to preserve prefix, padding, reset_policy
            $priorSequence = DocumentSequence::where('company_id', $companyId)
                ->where('document_type', $documentType)
                ->orderByDesc('year')
                ->first();

            $prefix = $priorSequence !== null ? $priorSequence->prefix : (self::DEFAULT_PREFIXES[$documentType] ?? strtoupper(substr($documentType, 0, 3)));
            $padding = $priorSequence !== null ? $priorSequence->padding : 4;
            $resetPolicy = $priorSequence !== null ? $priorSequence->reset_policy : 'yearly';

            $seqYear = $resetPolicy === 'never' ? 0 : $effectiveYear;

            $sequence = DocumentSequence::create([
                'company_id' => $companyId,
                'document_type' => $documentType,
                'prefix' => $prefix,
                'year' => $seqYear,
                'next_number' => 1,
                'padding' => $padding,
                'reset_policy' => $resetPolicy,
            ]);

            // Re-lock for update
            $sequence = DocumentSequence::where('id', $sequence->id)->lockForUpdate()->firstOrFail();
        }

        $currentNumber = $sequence->next_number;
        $padded = str_pad((string) $currentNumber, (int) $sequence->padding, '0', STR_PAD_LEFT);

        $formatted = ((int) $sequence->year > 0)
            ? "{$sequence->prefix}-{$sequence->year}-{$padded}"
            : "{$sequence->prefix}-{$padded}";

        $sequence->next_number = $currentNumber + 1;
        $sequence->save();

        return $formatted;
    }

    /**
     * Preview the next document number without advancing the sequence.
     */
    public function peekNextNumber(int $companyId, string $documentType, ?int $year = null): string
    {
        $effectiveYear = $year ?? (int) Carbon::now()->year;

        $neverSequence = DocumentSequence::where('company_id', $companyId)
            ->where('document_type', $documentType)
            ->where('reset_policy', 'never')
            ->first();

        if ($neverSequence !== null) {
            $padded = str_pad((string) $neverSequence->next_number, (int) $neverSequence->padding, '0', STR_PAD_LEFT);

            return "{$neverSequence->prefix}-{$padded}";
        }

        /** @var DocumentSequence|null $sequence */
        $sequence = DocumentSequence::where('company_id', $companyId)
            ->where('document_type', $documentType)
            ->where('year', $effectiveYear)
            ->first();

        if ($sequence === null) {
            $priorSequence = DocumentSequence::where('company_id', $companyId)
                ->where('document_type', $documentType)
                ->orderByDesc('year')
                ->first();

            $prefix = $priorSequence !== null ? $priorSequence->prefix : (self::DEFAULT_PREFIXES[$documentType] ?? strtoupper(substr($documentType, 0, 3)));
            $padding = $priorSequence !== null ? $priorSequence->padding : 4;
            $resetPolicy = $priorSequence !== null ? $priorSequence->reset_policy : 'yearly';

            $padded = str_pad('1', (int) $padding, '0', STR_PAD_LEFT);

            return $resetPolicy === 'never'
                ? "{$prefix}-{$padded}"
                : "{$prefix}-{$effectiveYear}-{$padded}";
        }

        $padded = str_pad((string) $sequence->next_number, (int) $sequence->padding, '0', STR_PAD_LEFT);

        return ((int) $sequence->year > 0)
            ? "{$sequence->prefix}-{$sequence->year}-{$padded}"
            : "{$sequence->prefix}-{$padded}";
    }

    /**
     * Ensure default sequences exist for all document types for a company.
     */
    public function ensureDefaultSequences(int $companyId, ?int $year = null): void
    {
        $effectiveYear = $year ?? (int) Carbon::now()->year;

        foreach (self::DEFAULT_PREFIXES as $type => $prefix) {
            DocumentSequence::firstOrCreate(
                [
                    'company_id' => $companyId,
                    'document_type' => $type,
                    'year' => $effectiveYear,
                ],
                [
                    'prefix' => $prefix,
                    'next_number' => 1,
                    'padding' => 4,
                    'reset_policy' => 'yearly',
                ]
            );
        }
    }
}
