<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Application\Reporting\DTO\ReportPeriod;
use DateTimeImmutable;

/** Untrusted request labels only. No identities, authority, query filters or execution methods.
 * A2 must resolve names and reject unsupported constraints under fresh canonical authorization.
 */
final readonly class AssistantIntentProposal
{
    public const array NAME_FILTERS = ['customer_name', 'vendor_name', 'product_name', 'warehouse_name', 'category_name'];

    public const array GROUPINGS = ['daily', 'weekly', 'monthly', 'quarterly', 'yearly', 'by_customer', 'by_vendor', 'by_product', 'by_warehouse', 'by_category'];

    public const array COMPARISONS = ['previous_period', 'previous_year'];

    public const int MAX_NAME_LENGTH = 255;

    public const int MAX_DATE_SPAN_DAYS = 366;

    /** @param array<string, string> $filters */
    private function __construct(public string $reportKey, public array $filters, public ?string $requestFragment = null) {}

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $fragment = $input['request_fragment'] ?? null;
        if (array_key_exists('request_fragment', $input)
            && (! is_string($fragment) || trim($fragment) !== $fragment || $fragment === ''
                || mb_strlen($fragment) > 4000 || preg_match('/[\p{Cc}\p{Cf}]/u', $fragment) !== 0)) {
            throw new AssistantUnavailable('provider_invalid');
        }
        unset($input['request_fragment']);
        $keys = array_keys($input);
        sort($keys);
        if ($keys !== ['display', 'filters', 'report_key', 'type', 'version']
            || $input['version'] !== 1 || $input['type'] !== 'report_query' || $input['display'] !== 'summary'
            || ! is_string($input['report_key']) || ! in_array($input['report_key'], AssistantReportCatalog::KEYS, true)
            || ! is_array($input['filters'])) {
            throw new AssistantUnavailable('provider_invalid');
        }
        $filters = [];
        foreach ($input['filters'] as $key => $value) {
            if (! is_string($key) || ! in_array($key, [...self::NAME_FILTERS, 'preset', 'start_date', 'end_date', 'currency_code', 'grouping', 'compare'], true)
                || ! is_string($value) || trim($value) === '' || trim($value) !== $value
                || mb_strlen($value) > self::MAX_NAME_LENGTH || preg_match('/[\p{Cc}\p{Cf}]/u', $value) !== 0) {
                throw new AssistantUnavailable('provider_invalid');
            }
            if (in_array($key, self::NAME_FILTERS, true) && str_contains($value, '://')) {
                throw new AssistantUnavailable('provider_invalid');
            }
            if (($key === 'preset' && ! in_array($value, ReportPeriod::ALLOWED_PRESETS, true))
                || ($key === 'currency_code' && ! preg_match('/\A[A-Z]{3}\z/D', $value))
                || ($key === 'grouping' && ! in_array($value, self::GROUPINGS, true))
                || ($key === 'compare' && ! in_array($value, self::COMPARISONS, true))) {
                throw new AssistantUnavailable('provider_invalid');
            }
            if (in_array($key, ['start_date', 'end_date'], true)) {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if ($date === false || ! preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/D', $value) || $date->format('Y-m-d') !== $value) {
                    throw new AssistantUnavailable('provider_invalid');
                }
            }
            $filters[$key] = $value;
        }
        $start = $filters['start_date'] ?? null;
        $end = $filters['end_date'] ?? null;
        if (($start === null) !== ($end === null)
            || (($filters['preset'] ?? null) === 'custom' && $start === null)
            || ($start !== null && isset($filters['preset']) && $filters['preset'] !== 'custom')) {
            throw new AssistantUnavailable('provider_invalid');
        }
        if ($start !== null && $end !== null
            && ($start > $end || (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days >= self::MAX_DATE_SPAN_DAYS)) {
            throw new AssistantUnavailable('provider_invalid');
        }
        if (isset($filters['compare']) && ! isset($filters['preset']) && $start === null) {
            throw new AssistantUnavailable('provider_invalid');
        }

        return new self($input['report_key'], $filters, $fragment);
    }
}
