<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\AI\AiRetention;
use App\Models\AssistantRequest;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class PruneAssistantProposals extends Command
{
    protected $signature = 'ai-assistant:prune';

    protected $description = 'Remove expired encrypted assistant proposal caches; preserve content-free usage audit';

    public function handle(): int
    {
        $count = 0;
        CompanyScope::executeWithoutScope(function () use (&$count): void {
            AssistantRequest::where('purpose', 'intent')->where('status', 'resolved')
                ->where('created_at', '<=', now()->subSeconds((int) config('ai_assistant.proposal_ttl_seconds')))
                ->chunkById(100, function ($requests) use (&$count): void {
                    foreach ($requests as $request) {
                        // Keep the historical namespace while retiring its former runtime engine.
                        Cache::forget('ai-sales-proposal:'.$request->company_id.':'.$request->user_id.':'.$request->request_key);
                        // Compare status to avoid overwriting an accepted/canceled command.
                        $count += AssistantRequest::whereKey($request->id)->where('status', 'resolved')->update(['status' => 'expired']);
                    }
                });
        });
        $retained = app(AiRetention::class)->prune();
        $this->info('Expired structured proposal caches removed: '.($count + $retained['expired']));
        $this->info('Expired encrypted provenance content cleared: '.$retained['cleared']);

        return self::SUCCESS;
    }
}
