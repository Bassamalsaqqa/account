<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Application\AI\AssistantIntentProposal;
use App\Application\Reporting\DTO\ReportPeriod;
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
            ? 'Propose an approved read-only report, not an executable query. Preserve raw names, period and currency; never invent IDs or figures. Custom dates must be paired, ordered and at most 366 days; never combine them with a non-custom preset. Comparisons require an explicit period. For independent requests, request_fragment must copy the complete exact user clause for this report, including all qualifiers. Never truncate a clause or copy qualifiers from another clause. A2 authorizes and associates clauses locally.'
            : 'Request human clarification when intent, entity, period or currency is ambiguous or unsupported.';
    }

    public function schema(JsonSchema $schema): array
    {
        $version = $schema->integer()->enum([1])->required();
        if ($this->toolName === 'clarify') {
            return ['version' => $version, 'reason' => $schema->string()->enum(self::CLARIFICATION_REASONS)->required()];
        }
        $filters = [];
        foreach (AssistantIntentProposal::NAME_FILTERS as $key) {
            $filters[$key] = $schema->string()->min(1)->max(AssistantIntentProposal::MAX_NAME_LENGTH);
        }
        $filters['preset'] = $schema->string()->enum(ReportPeriod::ALLOWED_PRESETS);
        $filters['start_date'] = $schema->string()->format('date');
        $filters['end_date'] = $schema->string()->format('date');
        $filters['currency_code'] = $schema->string()->pattern('^[A-Z]{3}$');
        $filters['grouping'] = $schema->string()->enum(AssistantIntentProposal::GROUPINGS);
        $filters['compare'] = $schema->string()->enum(AssistantIntentProposal::COMPARISONS);

        return ['version' => $version, 'type' => $schema->string()->enum(['report_query'])->required(),
            'report_key' => $schema->string()->enum($this->reportKeys)->required(),
            'filters' => $schema->object($filters)->withoutAdditionalProperties()->required(),
            'display' => $schema->string()->enum(['summary'])->required(),
            'request_fragment' => $schema->string()->min(1)->max(4000)];
    }

    public function handle(Request $request): string
    {
        throw new LogicException('Planning definitions cannot execute tools.');
    }
}
