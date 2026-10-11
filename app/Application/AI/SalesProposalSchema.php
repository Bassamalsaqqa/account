<?php

declare(strict_types=1);

namespace App\Application\AI;

use Brick\Math\BigDecimal;

final class SalesProposalSchema
{
    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function validate(array $input): array
    {
        if (($input['version'] ?? null) !== 1 || ($input['intent'] ?? null) !== 'draft_sales_invoice'
            || array_diff(array_keys($input), ['version', 'intent', 'customer', 'warehouse', 'currency', 'exchange_rate', 'issue_date', 'due_date', 'lines']) !== []) {
            throw new AssistantUnavailable('proposal_invalid');
        }
        foreach (['customer', 'warehouse', 'currency', 'issue_date', 'due_date'] as $field) {
            $input[$field] = $this->reference($input[$field] ?? null);
        }
        $input['exchange_rate'] = $this->decimal($input['exchange_rate'] ?? null, 10, true);
        if (! is_array($input['lines'] ?? null) || ! array_is_list($input['lines']) || count($input['lines']) > (int) config('ai_assistant.max_lines')) {
            throw new AssistantUnavailable('proposal_invalid');
        }
        foreach ($input['lines'] as &$line) {
            if (! is_array($line) || array_diff(array_keys($line), ['product', 'unit', 'quantity', 'unit_price', 'discount_type', 'discount_value', 'tax']) !== []) {
                throw new AssistantUnavailable('proposal_invalid');
            }
            foreach (['product', 'unit', 'tax'] as $field) {
                $line[$field] = $this->reference($line[$field] ?? null);
            }
            $line['quantity'] = $this->decimal($line['quantity'] ?? null, 6, true);
            $line['unit_price'] = $this->decimal($line['unit_price'] ?? null, 6, false);
            $line['discount_value'] = $this->decimal($line['discount_value'] ?? '0', 6, false);
            $line['discount_type'] ??= null;
            if (! in_array($line['discount_type'], [null, 'fixed', 'percent'], true)) {
                throw new AssistantUnavailable('proposal_invalid');
            }
        }
        unset($line);

        return $input;
    }

    public function normalizeDigits(string $value): string
    {
        return strtr($value, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٫' => '.']);
    }

    private function reference(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || mb_strlen($value) > 255 || preg_match('/[\x00-\x1f\x7f]/u', $value)) {
            throw new AssistantUnavailable('proposal_invalid');
        }

        return trim($value) ?: null;
    }

    private function decimal(mixed $value, int $scale, bool $positive): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new AssistantUnavailable('proposal_invalid');
        }
        $value = $this->normalizeDigits(trim($value));
        if (strlen($value) > 32 || ! preg_match('/\A\d{1,10}(\.\d{1,'.$scale.'})?\z/', $value)
            || ($positive && ! BigDecimal::of($value)->isPositive())) {
            throw new AssistantUnavailable('proposal_invalid');
        }

        return (string) BigDecimal::of($value)->strippedOfTrailingZeros();
    }
}
