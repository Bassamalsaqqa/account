<?php

declare(strict_types=1);

namespace Tests\Unit\Phase12;

use App\Application\AI\AssistantUnavailable;
use App\Application\AI\DeterministicTransactionParser;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeterministicTransactionParserTest extends TestCase
{
    public function test_complete_english_sales_and_arabic_purchase_preserve_exact_strings_without_provider_calls(): void
    {
        Http::preventStrayRequests();
        $parser = app(DeterministicTransactionParser::class);
        $sale = $parser->parse('sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS; 2 "piece" "BREAD" at 3.125 ILS');
        $this->assertSame('sales', $sale['intent']);
        $this->assertSame('Ahmed', $sale['party']);
        $this->assertSame('ILS', $sale['currency']);
        $this->assertSame('SOAP-3L', $sale['lines'][0]['product']);
        $this->assertSame('carton', $sale['lines'][0]['unit']);
        $this->assertSame('5', $sale['lines'][0]['quantity']);
        $this->assertSame('80', $sale['lines'][0]['unit_price']);
        $this->assertSame('3.125', $sale['lines'][1]['unit_price']);
        $this->assertNull($sale['lines'][0]['unit_cost']);
        $purchase = $parser->parse('شراء من "المورد": ٥ "كرتونة" "SOAP-3L" بسعر ٧٨.١٢٥ JOD', 'purchase');
        $this->assertSame('purchase', $purchase['intent']);
        $this->assertSame('5', $purchase['lines'][0]['quantity']);
        $this->assertSame('78.125', $purchase['lines'][0]['unit_cost']);
        $this->assertNull($purchase['vendor_invoice_number']);
        $this->assertNull($purchase['exchange_rate']);
        $this->assertSame([], $purchase['lines'][0]['lots']);
        Http::assertNothingSent();
    }

    public function test_unsupported_payments_and_partial_ambiguous_templates_never_become_purchases(): void
    {
        $parser = app(DeterministicTransactionParser::class);
        foreach (['vendor payment', 'receipt from Ahmed', 'دفعة للمورد', 'سند قبض'] as $text) {
            $this->assertSame('unsupported', $parser->parse($text)['intent']);
        }
        foreach ([
            'purchase from "Vendor": 5 carton SOAP at 80 ILS',
            'buy from "Vendor": -5 "carton" "SOAP" at 80 ILS',
            'buy from "Vendor": 5 "carton" "SOAP" at 8e2 ILS',
            'buy from "Vendor": 5 "carton" "SOAP" at 80 ILS; 2 "carton" "SOAP" at 80 USD',
            'buy from "Vendor": five "carton" "SOAP" at 80 ILS',
        ] as $text) {
            $this->assertNull($parser->parse($text));
        }
    }

    public function test_contextual_sales_action_refuses_purchase_intent(): void
    {
        $this->expectException(AssistantUnavailable::class);
        $this->expectExceptionMessage('unavailable');
        app(DeterministicTransactionParser::class)->parse('buy from "Vendor": 5 "carton" "SOAP" at 80 ILS', 'sales');
    }
}
