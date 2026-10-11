<?php

declare(strict_types=1);

namespace App\Application\AI;

use Brick\Math\BigDecimal;

final class FormPatchSchema
{
    private const FIELDS = ['quantity', 'unit_price', 'unit_cost', 'discount_percent', 'discount_fixed',
        'exchange_rate', 'notes', 'date', 'vendor_invoice_number', 'unit', 'tax'];

    /** @return array<string, mixed> */
    public function providerSchema(): array
    {
        $nullable = ['type' => ['string', 'null']];
        $properties = ['op' => ['type' => 'string', 'enum' => ['set', 'remove_line', 'add_line']],
            'line' => ['type' => ['integer', 'null']],
            'field' => ['type' => ['string', 'null'], 'enum' => [...self::FIELDS, null]],
            'value' => $nullable, 'product' => $nullable, 'unit' => $nullable, 'amount' => $nullable,
            'quote' => ['type' => 'string']];

        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['version', 'operations'],
            'properties' => ['version' => ['type' => 'integer', 'enum' => [1]],
                'operations' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 10,
                    'items' => ['type' => 'object', 'additionalProperties' => false,
                        'required' => array_keys($properties), 'properties' => $properties]]]];
    }

    /** @param array<string, mixed> $proposal
     * @return list<array<string, mixed>>
     */
    public function validate(array $proposal, string $utterance, int $lineCount): array
    {
        if (array_diff(array_keys($proposal), ['version', 'operations']) !== [] || ($proposal['version'] ?? null) !== 1
            || ! is_array($proposal['operations'] ?? null) || ! array_is_list($proposal['operations'])
            || count($proposal['operations']) < 1 || count($proposal['operations']) > 10) {
            throw new AssistantUnavailable('provider_invalid');
        }
        $keys = ['op', 'line', 'field', 'value', 'product', 'unit', 'amount', 'quote'];
        foreach ($proposal['operations'] as &$op) {
            if (! is_array($op) || array_diff(array_keys($op), $keys) !== [] || array_diff($keys, array_keys($op)) !== []
                || ! in_array($op['op'], ['set', 'remove_line', 'add_line'], true)
                || ! is_string($op['quote']) || $op['quote'] === '' || mb_strlen($op['quote']) > 1500
                || ! str_contains($utterance, $op['quote'])) {
                throw new AssistantUnavailable('provider_invalid');
            }
            foreach (['field', 'value', 'product', 'unit', 'amount'] as $field) {
                if ($op[$field] !== null && (! is_string($op[$field]) || mb_strlen($op[$field]) > 1000)) {
                    throw new AssistantUnavailable('provider_invalid');
                }
            }
            if ($op['op'] !== 'add_line') {
                if ($op['line'] === null && $lineCount === 1) {
                    $op['line'] = 1;
                }
                $header = $op['op'] === 'set' && in_array($op['field'], ['exchange_rate', 'notes', 'date', 'vendor_invoice_number'], true);
                if (! $header && (! is_int($op['line']) || $op['line'] < 1 || $op['line'] > $lineCount)) {
                    throw new AssistantUnavailable('choice_invalid');
                }
                if (! $header && $lineCount > 1 && ! preg_match('/(?:line|سطر|السطر)\s*'.preg_quote((string) $op['line'], '/').'(?!\d)/iu', $this->normalize($op['quote']))) {
                    throw new AssistantUnavailable('choice_invalid');
                }
            } elseif ($op['line'] !== null || $op['field'] !== null) {
                throw new AssistantUnavailable('provider_invalid');
            }
            if ($op['op'] === 'set') {
                if (! in_array($op['field'], self::FIELDS, true) || $op['value'] === null
                    || $op['product'] !== null || $op['unit'] !== null || $op['amount'] !== null) {
                    throw new AssistantUnavailable('provider_invalid');
                }
                $op['value'] = $this->value($op['field'], $op['value'], $op['quote']);
            } elseif ($op['op'] === 'remove_line') {
                if ($op['field'] !== null || $op['value'] !== null || $op['product'] !== null || $op['unit'] !== null || $op['amount'] !== null
                    || ! preg_match('/(?:remove|delete|احذف|حذف|شيل)/iu', $op['quote'])) {
                    throw new AssistantUnavailable('provider_invalid');
                }
            } else {
                if ($op['product'] === null || $op['unit'] === null || $op['value'] === null
                    || ! preg_match('/(?:add|ضيف|أضف|اضف)/iu', $op['quote'])) {
                    throw new AssistantUnavailable('provider_invalid');
                }
                foreach (['product', 'unit'] as $field) {
                    if (! str_contains($this->normalize($op['quote']), $this->normalize($op[$field]))) {
                        throw new AssistantUnavailable('provider_invalid');
                    }
                }
                $op['value'] = $this->value('quantity', $op['value'], $op['quote']);
                if ($op['amount'] !== null) {
                    $op['amount'] = $this->value('unit_price', $op['amount'], $op['quote']);
                }
            }
        }
        unset($op);

        return $proposal['operations'];
    }

    /** Complete single instructions consume no text-provider call.
     * @return array<string, mixed>|null
     */
    public function deterministic(string $text, int $lineCount): ?array
    {
        $normalized = $this->normalize(trim($text));
        $op = ['op' => 'set', 'line' => null, 'field' => null, 'value' => null, 'product' => null,
            'unit' => null, 'amount' => null, 'quote' => trim($text)];
        if (preg_match('/\A(?:set|change)\s+(?:line\s+(\d+)\s+)?(quantity|price|cost|discount)\s+(?:to\s+)?(\d+(?:\.\d+)?)(%)?\z/iu', $normalized, $m)) {
            $op['line'] = $m[1] === '' ? null : (int) $m[1];
            $op['field'] = match (strtolower($m[2])) {
                'quantity' => 'quantity', 'price' => 'unit_price', 'cost' => 'unit_cost',
                'discount' => isset($m[4]) && $m[4] === '%' ? 'discount_percent' : 'discount_fixed',
                default => throw new AssistantUnavailable('input_invalid'),
            };
            $op['value'] = $m[3];
        } elseif ($lineCount === 1 && preg_match('/\Aغير\s+الكمية\s+(?:من\s+\d+\s+)?(?:ل|إلى|الى)\s*(\d+(?:\.\d+)?)\z/u', $normalized, $m)) {
            $op['field'] = 'quantity';
            $op['value'] = $m[1];
        } elseif ($lineCount === 1 && preg_match('/\A(?:ضيف|أضف|اضف)\s+خصم\s+(\d+(?:\.\d+)?)\s*(?:بالمية|بالمئة|%)\z/u', $normalized, $m)) {
            $op['field'] = 'discount_percent';
            $op['value'] = $m[1];
        } elseif (preg_match('/\Aremove\s+line\s+(\d+)\z/iu', $normalized, $m)) {
            $op['op'] = 'remove_line';
            $op['line'] = (int) $m[1];
        } else {
            return null;
        }

        return ['version' => 1, 'operations' => [$op]];
    }

    private function value(string $field, string $value, string $quote): string
    {
        $numeric = in_array($field, ['quantity', 'unit_price', 'unit_cost', 'discount_percent', 'discount_fixed', 'exchange_rate'], true);
        $normalized = $this->normalize($value);
        $evidence = $this->normalize($quote);
        if ($numeric) {
            $scale = $field === 'exchange_rate' ? 10 : 6;
            if (! preg_match('/\A\d{1,10}(?:\.\d{1,'.$scale.'})?\z/', $normalized)
                || ! preg_match('/(?<![\d.])'.preg_quote($normalized, '/').'(?![\d.])/', $evidence)
                || (in_array($field, ['quantity', 'exchange_rate'], true) && ! BigDecimal::of($normalized)->isPositive())
                || ($field === 'discount_percent' && BigDecimal::of($normalized)->isGreaterThan(100))) {
                throw new AssistantUnavailable('provider_invalid');
            }

            return $normalized;
        }
        if ($value === '' || ! str_contains($evidence, $normalized)) {
            throw new AssistantUnavailable('provider_invalid');
        }

        return $value;
    }

    private function normalize(string $text): string
    {
        $text = app(SalesProposalSchema::class)->normalizeDigits($text);

        $numbers = ['واحد' => '1', 'اثنين' => '2', 'ثلاثة' => '3', 'اربعة' => '4', 'أربعة' => '4',
            'خمسة' => '5', 'خمس' => '5', 'ستة' => '6', 'سبعة' => '7', 'سبع' => '7',
            'ثمانية' => '8', 'تسعة' => '9', 'عشرة' => '10'];

        return preg_replace_callback('/(?<![\\p{L}\\p{N}])(ل?)('.implode('|', array_keys($numbers)).')(?![\\p{L}\\p{N}])/u',
            fn ($match) => $match[1].$numbers[$match[2]], $text);
    }
}
