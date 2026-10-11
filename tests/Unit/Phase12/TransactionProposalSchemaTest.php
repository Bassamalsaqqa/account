<?php

declare(strict_types=1);

namespace Tests\Unit\Phase12;

use App\Application\AI\AssistantUnavailable;
use App\Application\AI\DeterministicTransactionParser;
use App\Application\AI\TransactionProposalSchema;
use Tests\TestCase;

class TransactionProposalSchemaTest extends TestCase
{
    public function test_deterministic_sale_and_purchase_pass_independent_schema_and_exact_spoken_evidence(): void
    {
        foreach (['sell to "Ahmed": 5 "carton" "SOAP" at 80 ILS', 'شراء من "المورد": ٥ "كرتونة" "SOAP" بسعر ٧٨.١٢٥ JOD'] as $text) {
            $proposal = app(DeterministicTransactionParser::class)->parse($text);
            $validated = app(TransactionProposalSchema::class)->validate($proposal, $text, 'auto');
            $this->assertSame($proposal['intent'], $validated['intent']);
            $this->assertSame('5', $validated['lines'][0]['quantity']);
            $this->assertSame($proposal['lines'][0]['unit_price'], $validated['lines'][0]['unit_price']);
            $this->assertSame($proposal['lines'][0]['unit_cost'], $validated['lines'][0]['unit_cost']);
        }
    }

    public function test_fabricated_fields_numeric_floats_exponents_confidence_and_changed_intent_fail(): void
    {
        $text = 'sell to "Ahmed": 5 "carton" "SOAP" at 80 ILS';
        $proposal = app(DeterministicTransactionParser::class)->parse($text);
        $cases = [
            [...$proposal, 'customer_id' => 1],
            [...$proposal, 'confidence' => 100],
            [...$proposal, 'intent' => 'purchase'],
            [...$proposal, 'evidence' => [...$proposal['evidence'], 'party' => 'Other']],
            [...$proposal, 'lines' => [[...$proposal['lines'][0], 'quantity' => 5.0]]],
            [...$proposal, 'lines' => [[...$proposal['lines'][0], 'quantity' => '5e0']]],
            [...$proposal, 'lines' => [[...$proposal['lines'][0], 'unit_price' => '800']]],
            [...$proposal, 'lines' => [[...$proposal['lines'][0], 'unit_price' => '80.1234567']]],
            [...$proposal, 'lines' => [[...$proposal['lines'][0], 'product_id' => 1]]],
        ];
        foreach ($cases as $case) {
            try {
                app(TransactionProposalSchema::class)->validate($case, $text, 'sales');
                $this->fail('Untrusted or fabricated output must refuse.');
            } catch (AssistantUnavailable $e) {
                $this->assertContains($e->reason, ['proposal_invalid', 'evidence_invalid', 'intent_mismatch']);
            }
        }
    }

    public function test_provider_evidence_list_and_partial_lines_are_valid_but_missing_evidence_is_not(): void
    {
        $text = 'sell to "Ahmed": 5 "carton" "SOAP" at 80 ILS';
        $proposal = app(DeterministicTransactionParser::class)->parse($text);
        $proposal['evidence'] = array_map(fn ($path, $quote) => ['path' => $path, 'quote' => $quote],
            array_keys($proposal['evidence']), array_values($proposal['evidence']));
        $proposal['lines'][0]['unit_price'] = null;
        $validated = app(TransactionProposalSchema::class)->validate($proposal, $text, 'auto');
        $this->assertNull($validated['lines'][0]['unit_price']);
        $this->assertSame('SOAP', $validated['lines'][0]['product']);
        $proposal['evidence'] = [];
        try {
            app(TransactionProposalSchema::class)->validate($proposal, $text, 'auto');
            $this->fail('Model fields require local evidence.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('evidence_invalid', $e->reason);
        }
    }

    public function test_negative_embedded_number_is_not_proof_for_a_positive_value(): void
    {
        $proposal = app(DeterministicTransactionParser::class)->parse('sell to "Ahmed": 5 "carton" "SOAP" at 80 ILS');
        foreach (['sell to "Ahmed": -5 "carton" "SOAP" at 80 ILS', 'sell to "Ahmed": 145 "carton" "SOAP" at 80 ILS'] as $text) {
            try {
                app(TransactionProposalSchema::class)->validate($proposal, $text, 'sales');
                $this->fail('Embedded or negative numeral must not validate positive quantity.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame('evidence_invalid', $e->reason);
            }
        }
    }
}
