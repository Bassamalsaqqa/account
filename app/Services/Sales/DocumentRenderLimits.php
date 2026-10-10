<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Domain\Sales\Documents\DocumentData;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Preflight bounds apply before template hydration and before any output is delivered. */
final class DocumentRenderLimits
{
    public const int MAX_DOCUMENT_LINES = 500;

    public const int MAX_STATEMENT_ENTRIES = 1000;

    public const int MAX_PDF_BYTES = 15 * 1024 * 1024;

    public const int MAX_PAGES = 50;

    public const int MAX_SECONDS = 15;

    public function assertStatementSource(int $companyId, int $customerId): void
    {
        $count = 0;
        foreach (['sales_invoices', 'sales_returns', 'customer_payments'] as $table) {
            $count += DB::table($table)->where('company_id', $companyId)->where('customer_id', $customerId)->count();
        }
        $count += DB::table('customer_payment_application_events')->where('company_id', $companyId)
            ->whereIn('customer_payment_id', DB::table('customer_payments')->select('id')->where('company_id', $companyId)->where('customer_id', $customerId))->count();
        if ($count > self::MAX_STATEMENT_ENTRIES) {
            throw new InvalidArgumentException('Statement history exceeds the supported source limit.');
        }
    }

    public function assertDocument(DocumentData $data): void
    {
        if (! in_array($data->locale, ['ar', 'en'], true) || count($data->lines) + count($data->applications) > self::MAX_DOCUMENT_LINES) {
            throw new InvalidArgumentException('Document exceeds the supported locale or line limits.');
        }
        $entries = 0;
        foreach ($data->statement['currencies'] ?? [] as $group) {
            $entries += count($group['entries'] ?? []);
        }
        if ($entries > self::MAX_STATEMENT_ENTRIES) {
            throw new InvalidArgumentException('Statement exceeds the supported entry limit.');
        }
        $this->assertValues($data->toArray());
        $textBytes = 0;
        $imageBytes = 0;
        $values = $data->toArray();
        array_walk_recursive($values, static function ($value, $key) use (&$textBytes, &$imageBytes): void {
            if (is_string($value)) {
                if (in_array($key, ['image', 'logo'], true)) {
                    $imageBytes += strlen($value);
                } else {
                    $textBytes += strlen($value);
                }
            }
        });
        if ($textBytes > 256 * 1024 || $imageBytes > 4 * 1024 * 1024) {
            throw new InvalidArgumentException('Document text exceeds the preparation budget.');
        }
        if (strlen(json_encode($data->toArray(), JSON_THROW_ON_ERROR)) > self::MAX_PDF_BYTES) {
            throw new InvalidArgumentException('Document exceeds the preparation byte limit.');
        }
    }

    /** @param array<array-key, mixed> $values */
    private function assertValues(array $values): void
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $this->assertValues($value);
            } elseif (is_float($value) || is_object($value)) {
                throw new InvalidArgumentException('Document values must be explicit exact primitives.');
            } elseif (is_string($value)) {
                $isImage = in_array($key, ['image', 'logo'], true);
                if (strlen($value) > ($isImage ? 3 * 1024 * 1024 : 20000)) {
                    throw new InvalidArgumentException('Document text or image exceeds its preparation limit.');
                }
            }
        }
    }
}
