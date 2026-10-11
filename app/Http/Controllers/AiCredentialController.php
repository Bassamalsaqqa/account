<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\AI\AiGovernance;
use App\Application\AI\AssistantUnavailable;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class AiCredentialController
{
    public function __invoke(Request $request, CompanyContext $context): RedirectResponse
    {
        $values = $request->validate([
            'operation' => ['required', 'in:create,replace,revoke,validate'],
            'connection_id' => ['nullable', 'uuid'], 'route' => ['nullable', 'string', 'max:255'],
            'credential_source' => ['required', 'in:company_byok,personal_byok'],
            'password' => ['required', 'string', 'max:4096'],
            'provider_secret' => ['nullable', 'string', 'max:4096'],
        ]);
        $governance = app(AiGovernance::class);
        try {
            if (in_array($values['operation'], ['create', 'replace'], true)) {
                $parts = explode('|', $values['route'] ?? '');
                if (count($parts) !== 4 || ! is_string($values['provider_secret'] ?? null)
                    || ($values['operation'] === 'replace' && empty($values['connection_id']))) {
                    throw new AssistantUnavailable('provider_configuration');
                }
                $governance->provision($context->company(), $request->user(), [
                    'provider' => $parts[0], 'model' => $parts[1], 'region' => $parts[2], 'capability' => $parts[3],
                    'credential_source' => $values['credential_source'],
                ], $values['provider_secret'], $values['password'], $values['operation'] === 'replace' ? $values['connection_id'] : null);
            } else {
                if (empty($values['connection_id'])) {
                    throw new AssistantUnavailable('provider_configuration');
                }
                if ($values['operation'] === 'revoke') {
                    $governance->revoke($context->company(), $request->user(), $values['connection_id'], $values['password']);
                } else {
                    $governance->validateConnection($context->company(), $request->user(), $values['connection_id'], $values['password']);
                }
            }
        } catch (AssistantUnavailable) {
            // Never flash submitted secrets, upstream payloads or exception details.
            return redirect()->route('settings.ai')->with('ai_settings_error', __('assistant_v2.error_unavailable'));
        }

        return redirect()->route('settings.ai')->with('ai_settings_success', __('assistant_v2.configured_unverified'));
    }
}
