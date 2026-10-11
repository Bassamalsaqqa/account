<?php

declare(strict_types=1);

namespace App\Application\AI;

/**
 * Deliberately narrow, complete templates. Other notes use the untrusted model path.
 * No catalog data or financial calculation is sent to a model on this path.
 */
final class DeterministicTransactionParser
{
    /** @return array<string, mixed>|null */
    public function parse(string $text, string $context = 'auto'): ?array
    {
        if (! in_array($context, ['auto', 'sales', 'purchase'], true)
            || mb_strlen($text) > (int) config('ai_assistant.max_text_characters')) {
            throw new AssistantUnavailable('input_invalid');
        }
        $normalized = app(SalesProposalSchema::class)->normalizeDigits(trim($text));
        if (preg_match('/(?:\b(?:payment|receipt|expense|refund|transfer)\b|دفعة|دفع|سند\s*قبض|سند\s*صرف|مصروف|تحويل)/iu', $normalized)) {
            return ['version' => 2, 'intent' => 'unsupported'];
        }
        // Example: sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS; 2 "piece" "BREAD" at 3 ILS
        // Arabic: بيع إلى "أحمد": 5 "كرتونة" "SOAP-3L" بسعر 80 ILS
        if (! preg_match('/\A(sell\s+to|purchase\s+from|buy\s+from|بيع\s+(?:إلى|الى|ل)|شراء\s+من)\s+"([^"\r\n]{1,255})"\s*:\s*(.+)\z/iu', $normalized, $head)) {
            return null;
        }
        $intent = preg_match('/\A(?:sell|بيع)/iu', $head[1]) ? 'sales' : 'purchase';
        if ($context !== 'auto' && $intent !== $context) {
            throw new AssistantUnavailable('intent_mismatch');
        }
        $parts = preg_split('/\s*[;؛]\s*/u', $head[3]);
        if ($parts === false || count($parts) > (int) config('ai_assistant.max_lines')) {
            return null;
        }
        $lines = [];
        $currency = null;
        $evidence = ['party' => $head[2]];
        foreach ($parts as $index => $part) {
            if (! preg_match('/\A(\d{1,10}(?:\.\d{1,6})?)\s+"([^"\r\n]{1,255})"\s+"([^"\r\n]{1,255})"\s+(?:at|بسعر)\s+(\d{1,10}(?:\.\d{1,6})?)\s+(ILS|USD|JOD)\z/iu', $part, $match)) {
                return null;
            }
            if ($currency !== null && $currency !== strtoupper($match[5])) {
                return null;
            }
            $currency = strtoupper($match[5]);
            $lines[] = ['product' => $match[3], 'unit' => $match[2], 'quantity' => $match[1],
                'unit_price' => $intent === 'sales' ? $match[4] : null,
                'unit_cost' => $intent === 'purchase' ? $match[4] : null,
                'discount_type' => null, 'discount_value' => null, 'tax' => null, 'lots' => []];
            foreach (['quantity' => 1, 'unit' => 2, 'product' => 3, $intent === 'sales' ? 'unit_price' : 'unit_cost' => 4] as $field => $position) {
                $evidence['lines.'.$index.'.'.$field] = $match[$position];
            }
        }

        return ['version' => 2, 'intent' => $intent, 'party' => $head[2], 'currency' => $currency,
            'warehouse' => null, 'exchange_rate' => null, 'date' => null, 'due_date' => null,
            'vendor_invoice_number' => null, 'notes' => null, 'lines' => $lines,
            'evidence' => [...$evidence, 'currency' => $currency]];
    }
}
