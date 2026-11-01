<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Platform\SupportViewSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class PlatformSupportController extends Controller
{
    public function start(Request $request, string $publicId, SupportViewSession $support): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200', 'not_regex:/[\x00-\x1F\x7F]/'], 'current_password' => ['required', 'string', 'max:4096']]);
        $support->start($request, $publicId, trim($data['reason']), $data['current_password']);

        return redirect()->route('dashboard');
    }

    public function end(Request $request, SupportViewSession $support): RedirectResponse
    {
        $support->end($request);

        return redirect()->route('platform.index');
    }

    public function locale(Request $request): RedirectResponse
    {
        abort_unless($request->session()->has(SupportViewSession::KEY), 403);
        try {
            app(SupportViewSession::class)->resolve($request);
        } catch (\Throwable) {
            app(SupportViewSession::class)->end($request, 'authorization_expired');

            return redirect()->route('platform.index')->with('platform_success', __('support.expired'));
        }
        $data = $request->validate(['locale' => ['required', 'in:ar,en']]);
        $request->session()->put('locale', $data['locale']);

        return redirect()->route('dashboard');
    }
}
