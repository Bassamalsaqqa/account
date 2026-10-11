<?php

declare(strict_types=1);

namespace App\Application\AI;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Integer USD micro-units for operational budgeting, never accounting entries. */
final class ProviderCostEstimate
{
    public function audio(string $provider, int $milliseconds, int $microsPerMinute): int
    {
        if ($milliseconds < 1 || $milliseconds > 60000 || $microsPerMinute < 0 || $microsPerMinute > 1000000000) {
            throw new AssistantUnavailable('budget_configuration');
        }
        $floor = $provider === 'groq' ? 10000 : 0;
        $billedMilliseconds = max($floor, $milliseconds);

        return BigDecimal::of($billedMilliseconds)->multipliedBy($microsPerMinute)
            ->dividedBy(60000, 0, RoundingMode::CEILING)->toInt();
    }

    public function tokens(int $input, int $output, int $inputMicrosPerMillion, int $outputMicrosPerMillion): int
    {
        foreach ([$input, $output, $inputMicrosPerMillion, $outputMicrosPerMillion] as $value) {
            if ($value < 0 || $value > 1000000000) {
                throw new AssistantUnavailable('budget_configuration');
            }
        }

        return BigDecimal::of($input)->multipliedBy($inputMicrosPerMillion)
            ->plus(BigDecimal::of($output)->multipliedBy($outputMicrosPerMillion))
            ->dividedBy(1000000, 0, RoundingMode::CEILING)->toInt();
    }
}
