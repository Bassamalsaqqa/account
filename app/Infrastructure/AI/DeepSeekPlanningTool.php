<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use LogicException;

/** Static SDK schema only. Canonical tool execution belongs to the authorized application layer. */
final class DeepSeekPlanningTool implements Tool
{
    public const array CLARIFICATION_REASONS = ['intent', 'entity', 'period', 'currency', 'unsupported'];

    /** @param list<string> $reportKeys */
    private function __construct(private readonly string $toolName, private readonly array $reportKeys = []) {}

    /** @param list<string> $reportKeys */
    public static function report(array $reportKeys): self
    {
        return new self('run_report', $reportKeys);
    }

    public static function clarify(): self
    {
        return new self('clarify');
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return $this->toolName === 'run_report'
            ? 'Propose an approved read-only report. Preserve requested names, period and currency. Never invent identifiers or figures.'
            : 'Request human clarification when intent, entity, period or currency is ambiguous or unsupported.';
    }

    public function schema(JsonSchema $schema): array
    {
        $version = $schema->integer()->enum([1])->required();
        if ($this->toolName === 'clarify') {
            return ['version' => $version, 'reason' => $schema->string()->enum(self::CLARIFICATION_REASONS)->required()];
        }
        $filters = [];
        foreach (['preset', 'start_date', 'end_date', 'currency_code', 'grouping', 'customer_name',
            'vendor_name', 'product_name', 'warehouse_name', 'category_name', 'compare'] as $key) {
            $filters[$key] = $schema->string()->min(1)->max(255);
        }

        return ['version' => $version, 'type' => $schema->string()->enum(['report_query'])->required(),
            'report_key' => $schema->string()->enum($this->reportKeys)->required(),
            'filters' => $schema->object($filters)->withoutAdditionalProperties()->required(),
            'display' => $schema->string()->enum(['summary'])->required()];
    }

    public function handle(Request $request): string
    {
        throw new LogicException('Planning definitions cannot execute tools.');
    }
}
