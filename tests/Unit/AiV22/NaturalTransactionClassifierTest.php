<?php

declare(strict_types=1);

namespace Tests\Unit\AiV22;

use App\Application\AI\AssistantDeterministicClassifier;
use App\Application\AI\AssistantUnavailable;
use Tests\TestCase;

final class NaturalTransactionClassifierTest extends TestCase
{
    public function test_past_tense_business_questions_are_never_draft_commands(): void
    {
        foreach (['Which products sold most this month?', 'What did we buy from Supplier last month?',
            'How much did we sell to Ahmed?', 'شو بعنا لأحمد هالشهر؟', 'قديش اشترينا من المورد؟'] as $text) {
            try {
                $result = app(AssistantDeterministicClassifier::class)->classify($text);
                self::assertNotSame('transaction', $result['kind'] ?? null, $text);
                self::assertNotSame('ambiguous_operation', $result['kind'] ?? null, $text);
            } catch (AssistantUnavailable $exception) {
                // Named report constraints may require the A2 planner; this is not a draft.
                self::assertSame('question_invalid', $exception->reason);
            }
        }
    }
}
