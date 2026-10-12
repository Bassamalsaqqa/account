<?php

declare(strict_types=1);

namespace App\Providers;

use App\Livewire\AI\AssistantLauncher;
use App\Livewire\AI\DeepSeekSetup;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

final class Phase12ServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Automatic "ai" -> "Ai" discovery does not match the AI directory on Linux.
        Livewire::component('ai.assistant-launcher', AssistantLauncher::class);
        Livewire::component('ai.deep-seek-setup', DeepSeekSetup::class);
    }
}
