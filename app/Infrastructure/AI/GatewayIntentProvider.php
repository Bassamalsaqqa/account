<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Application\AI\AssistantUnavailable;
use App\Application\AI\StructuredIntentProvider;

final class GatewayIntentProvider implements StructuredIntentProvider
{
    public function __construct(private GatewayTransport $transport) {}

    public function extract(string $text, string $locale): array
    {
        $data = $this->transport->send('intent', ['version' => 1, 'intent' => 'draft_sales_invoice', 'text' => $text, 'locale' => $locale]);
        if (array_diff(array_keys($data), ['proposal', 'confidence', 'usage']) !== []
            || ! is_array($data['proposal'] ?? null) || ! is_int($data['confidence'] ?? null)
            || ! is_array($data['usage'] ?? null)) {
            throw new AssistantUnavailable('provider_invalid');
        }

        return ['proposal' => $data['proposal'], 'confidence' => $data['confidence'], 'usage' => $data['usage']];
    }
}
