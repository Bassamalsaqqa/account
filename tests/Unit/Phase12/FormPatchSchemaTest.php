<?php

declare(strict_types=1);

namespace Tests\Unit\Phase12;

use App\Application\AI\AssistantUnavailable;
use App\Application\AI\FormPatchSchema;
use Tests\TestCase;

class FormPatchSchemaTest extends TestCase
{
    public function test_exact_arabic_follow_ups_and_line_ordinals_are_bounded(): void
    {
        $schema = app(FormPatchSchema::class);
        foreach (['غير الكمية من خمس لسبع', 'change quantity to 7'] as $text) {
            $proposal = $schema->deterministic($text, 1);
            $this->assertIsArray($proposal);
            $ops = $schema->validate($proposal, $text, 1);
            $this->assertSame(1, $ops[0]['line']);
            $this->assertSame('7', $ops[0]['value']);
        }
        $text = 'set line 2 discount 10%';
        $ops = $schema->validate($schema->deterministic($text, 2), $text, 2);
        $this->assertSame('discount_percent', $ops[0]['field']);
        $this->assertSame(2, $ops[0]['line']);
        $this->assertSame('10', $ops[0]['value']);
        $this->assertNull($schema->deterministic('غير الكمية من خمس لخمسين', 1));
    }

    public function test_malformed_arbitrary_fields_floats_and_ambiguous_lines_fail_closed(): void
    {
        $schema = app(FormPatchSchema::class);
        $base = $schema->deterministic('change quantity to 7', 1);
        $bad = [];
        $bad[] = [...$base, 'sql' => 'not permitted'];
        $float = $base;
        $float['operations'][0]['value'] = 7.0;
        $bad[] = $float;
        $id = $base;
        $id['operations'][0]['product_id'] = 1;
        $bad[] = $id;
        $fabricated = $base;
        $fabricated['operations'][0]['value'] = '70';
        $bad[] = $fabricated;
        foreach ($bad as $proposal) {
            try {
                $schema->validate($proposal, 'change quantity to 7', 1);
                $this->fail('Untrusted operation must refuse.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame('provider_invalid', $e->reason);
            }
        }
        try {
            $schema->validate($base, 'change quantity to 7', 2);
            $this->fail('Multiple current lines require an explicit ordinal.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('choice_invalid', $e->reason);
        }
    }
}
