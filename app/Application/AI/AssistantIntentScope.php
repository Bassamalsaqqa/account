<?php

declare(strict_types=1);

namespace App\Application\AI;

/** Associate untrusted labels with complete server-recognized request clauses, never executable authority. */
final class AssistantIntentScope
{
    /** @param list<array<string, mixed>> $proposals
     * @return list<array{text: string, proposal: array<string, mixed>}>
     */
    public function associate(string $text, array $proposals): array
    {
        if ($proposals === [] || count($proposals) > 3) {
            throw new AssistantUnavailable('question_invalid');
        }
        $clauses = $this->clauses($text);
        $covered = [];
        $scopes = [];
        foreach ($proposals as $proposal) {
            $intent = AssistantIntentProposal::fromArray($proposal);
            if (count($clauses) === 1) {
                $candidates = [0];
            } elseif ($intent->requestFragment !== null) {
                $candidates = array_keys($clauses, $intent->requestFragment, true);
            } else {
                $candidates = [];
                foreach ($clauses as $index => $clause) {
                    if ($this->matchesDomain($clause, $intent->reportKey)) {
                        $candidates[] = $index;
                    }
                }
            }
            if (count($candidates) !== 1) {
                throw new AssistantUnavailable('question_invalid');
            }
            $index = $candidates[0];
            $clause = $clauses[$index];
            // A model cannot trim a trailing qualifier, add a qualifier, or cross a clause boundary.
            if ($intent->requestFragment !== null && $intent->requestFragment !== $clause) {
                throw new AssistantUnavailable('question_invalid');
            }
            if (count($clauses) > 1 && ! $this->matchesDomain($clause, $intent->reportKey)) {
                throw new AssistantUnavailable('question_invalid');
            }
            unset($proposal['request_fragment']);
            $scopes[] = ['text' => $clause, 'proposal' => $proposal];
            $covered[$index] = true;
        }
        // Every independent clause must be accounted for, including unsupported or unrelated tails.
        if (count($covered) !== count($clauses)) {
            throw new AssistantUnavailable('question_invalid');
        }

        return $scopes;
    }

    /** @return list<string> */
    private function clauses(string $text): array
    {
        $text = trim($text);
        $english = '(?:(?:show|get|give|compare|tell\\s+me(?:\\s+about)?|what(?:\\s+(?:about|were|was|are|is))?)\\s+)?(?:(?:our|the|my)\\s+)?(?:sales|purchases|buying|inventory|stock|expenses|customer(?:s)?\\s+balances?|vendor(?:s)?\\s+balances?|balances?)\\b';
        $arabic = '(?:(?:شو|قديش|كم|ورجيني|اعرض|قارن)\\s+)?(?:مبيعات|المبيعات|مشتريات|المشتريات|مخزون|المخزون|اصناف|الأصناف|مصروفات|المصروفات|رصيد|ارصدة|أرصدة|ديون|دين|على\\s+\\p{L})';
        $anchor = '(?:'.$english.'|'.$arabic.')';
        $parts = preg_split('/(?:\\s*[;؛]\\s*|\\R+|\\s*[?؟]\\s*(?=\\S)|(?:,\\s*|\\s+)(?:and|also)\\s+(?='.$anchor.')|(?:\\s+|،\\s*|,\\s*)و(?='.$anchor.'))/iu', $text);
        if (! is_array($parts)) {
            throw new AssistantUnavailable('question_invalid');
        }
        $clauses = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $clauses[] = $part;
            }
        }
        // The established debt + "his last invoice" question has one shared party scope.
        // This is deliberately narrow: an explicit different party, date or currency never merges.
        if (count($clauses) === 2 && $this->matchesDomain($clauses[0], 'customers.balances')) {
            $tail = TenantSpeechCandidates::normalize($clauses[1]);
            if (preg_match('/\A(?:و?شو\s+)?اخر\s+فاتوره\s+(?:اله|له|لنفس\s+الزبون)[؟?.!]*\z/u', $tail)
                || preg_match('/\A(?:and\s+)?(?:what\s+is\s+)?(?:his|their|the\s+same\s+customer.s)\s+(?:last|latest)\s+invoice[?.!]*\z/u', $tail)) {
                return [$text];
            }
        }
        if ($clauses === [] || count($clauses) > 3) {
            throw new AssistantUnavailable('question_invalid');
        }

        return $clauses;
    }

    private function matchesDomain(string $clause, string $reportKey): bool
    {
        $text = TenantSpeechCandidates::normalize($clause);
        $patterns = [
            'sales' => '/\\b(?:sales|sold|selling|invoice)\\b|مبيعات|بعنا|فاتوره/u',
            'purchases' => '/\\b(?:purchases|purchase|buying|bought)\\b|مشتريات|شراء|اشترينا/u',
            'customers' => '/\\b(?:customer|client|debt|receivables|balance)\\b|زبون|عميل|دين|ديون|رصيد|قديش\\s+على/u',
            'vendors' => '/\\b(?:vendor|supplier|payables|balance)\\b|مورد|رصيد|ديون/u',
            'inventory' => '/\\b(?:inventory|stock|items|expiry|expiring)\\b|مخزون|اصناف|حركه|صلاح/u',
            'expenses' => '/\\b(?:expense|expenses)\\b|مصروف|نفقات/u',
            'money' => '/\\b(?:cash|bank|treasury|money)\\b|نقد|بنك|خزين/u',
        ];
        $domain = explode('.', $reportKey)[0];
        if (! isset($patterns[$domain]) || preg_match($patterns[$domain], $text) !== 1) {
            return false;
        }
        // Explicit party types cannot be substituted even when both mention a balance.
        if ($domain === 'customers' && preg_match('/\\b(?:vendor|supplier)\\b|مورد/u', $text)) {
            return false;
        }
        if ($domain === 'vendors' && preg_match('/\\b(?:customer|client)\\b|زبون|عميل/u', $text)) {
            return false;
        }

        return true;
    }
}
