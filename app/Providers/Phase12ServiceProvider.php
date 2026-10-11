<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\AI\SpeechTranscriptionProvider;
use App\Application\AI\StructuredIntentProvider;
use App\Infrastructure\AI\GatewayIntentProvider;
use App\Infrastructure\AI\GatewaySpeechProvider;
use Illuminate\Support\ServiceProvider;

final class Phase12ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StructuredIntentProvider::class, GatewayIntentProvider::class);
        $this->app->bind(SpeechTranscriptionProvider::class, GatewaySpeechProvider::class);
    }
}
