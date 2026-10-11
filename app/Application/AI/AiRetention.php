<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Models\AiCompanyPolicy;
use App\Models\AssistantRequest;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class AiRetention
{
    /** Explicit maintenance only; retains content-free usage and provenance identifiers.
     * @return array{expired: int, cleared: int}
     */
    public function prune(): array
    {
        return CompanyScope::executeWithoutScope(function (): array {
            $expired = 0;
            $cleared = 0;
            $open = ['pending', 'form_ready', 'clarifying', 'transcribed', 'form_edited'];
            AssistantRequest::whereIn('status', $open)
                ->where('created_at', '<=', now()->subSeconds((int) config('ai_assistant.proposal_ttl_seconds')))
                ->select(['id', 'company_id', 'user_id'])->chunkById(100, function ($requests) use (&$expired, $open): void {
                    foreach ($requests as $request) {
                        $locator = 'ai-request-state:'.$request->company_id.':'.$request->user_id.':'.$request->id;
                        $token = Cache::get($locator);
                        if (is_string($token) && Str::isUuid($token)) {
                            Cache::forget('ai-form-state:'.hash('sha256', $token));
                        }
                        Cache::forget($locator);
                        $expired += AssistantRequest::whereKey($request->id)->whereIn('status', $open)
                            ->update(['status' => 'expired']);
                    }
                });
            AssistantRequest::whereNotNull('approved_command')->where('created_at', '<=', now()->subDay())
                ->select(['id', 'company_id', 'created_at'])->chunkById(100, function ($requests) use (&$cleared): void {
                    $policies = AiCompanyPolicy::whereIn('company_id', $requests->pluck('company_id')->unique())
                        ->get(['company_id', 'settings'])->keyBy('company_id');
                    foreach ($requests as $request) {
                        $days = min(365, max(1, (int) ($policies->get($request->company_id)?->settings['retention_days'] ?? 30)));
                        if ($request->created_at->isAfter(now()->subDays($days))) {
                            continue;
                        }
                        // Never select/decrypt content just to delete it. Canonical documents are untouched.
                        $cleared += AssistantRequest::whereKey($request->id)->whereNotNull('approved_command')
                            ->update(['approved_command' => null]);
                    }
                });

            return compact('expired', 'cleared');
        });
    }
}
