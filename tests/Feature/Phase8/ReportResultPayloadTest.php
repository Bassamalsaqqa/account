<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Application\Reporting\DTO\ReportResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ReportResultPayloadTest extends TestCase
{
    public function test_exact_authorized_scalar_payload_survives_serialization(): void
    {
        $report = new ReportResult('fixture', ['page' => 1], ['currencies' => ['JOD' => '-0.001']], [['name' => 'اسم', 'amount' => '12.345678', 'unavailable' => null]], ['base_currency_code' => 'ILS']);
        $this->assertSame('12.345678', $report->toArray()['rows'][0]['amount']);
        $this->assertSame('-0.001', $report->toArray()['totals']['currencies']['JOD']);
        $this->assertNull($report->toArray()['rows'][0]['unavailable']);
    }

    public function test_an_object_cannot_serialize_extra_model_fields_through_result(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReportResult('fixture', [], [], [['source' => (object) ['attachment_path' => 'private']]], []);
    }

    public function test_float_money_is_rejected_at_result_boundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReportResult('fixture', [], ['money' => 0.1], [], []);
    }
}
