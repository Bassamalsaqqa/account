<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Platform\SupportViewContext;
use App\Application\Platform\SupportViewSession;
use App\Support\Tenancy\CompanyContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Mechanisms\HandleComponents\Checksum;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

final class ApplySupportView
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->session()->has(SupportViewSession::KEY)) {
            return $next($request);
        }
        if ($request->routeIs('platform.support.end', 'platform.support.locale')) {
            return $next($request);
        }
        $session = app(SupportViewSession::class);
        try {
            $resolved = $session->resolve($request);
        } catch (\Throwable) {
            $session->end($request, 'authorization_expired');

            return $request->expectsJson() ? response()->json(['message' => __('support.expired')], 403) : redirect()->route('platform.index')->with('platform_success', __('support.expired'));
        }
        // A signed product-index filter/page request is the only Livewire exception.
        $readUpdate = $this->productReadUpdate($request);
        if (! $readUpdate && (! $request->isMethod('GET') || ! $request->routeIs('dashboard', 'products.*', 'customers.*', 'vendors.*', 'inventory.*', 'invoices.*', 'quotations.*', 'returns.*', 'payments.*', 'purchases.*', 'purchase-returns.*', 'vendor-payments.*', 'money.*', 'reports.index', 'reports.show', 'settings.index'))) {
            return response()->view('platform.support-denied', [], 403)->header('Cache-Control', 'no-store');
        }
        $operator = Auth::user();
        $context = app(CompanyContext::class);
        $support = app(SupportViewContext::class);
        // Warm the normal permission catalogue before enabling the no-business-write backstop.
        app(PermissionRegistrar::class)->getPermissions();
        Auth::setUser($resolved['target']);
        $context->setCompany($resolved['company'], $resolved['target']);
        $support->activate($resolved['details']);
        $previousAiEnabled = config('ai_assistant.enabled');
        config(['ai_assistant.enabled' => false]);
        DB::connection()->beforeExecuting(static function (string $sql) use ($support): void {
            if ($support->active() && (! preg_match('/^\s*\(*\s*(SELECT|SHOW|DESCRIBE)\b/i', $sql) || preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $sql))) {
                throw new AuthorizationException(__('support.read_only'));
            }
        });
        try {
            return $next($request)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'same-origin');
        } catch (AuthorizationException) {
            return response()->view('platform.support-denied', [], 403)->header('Cache-Control', 'no-store, private');
        } finally {
            config(['ai_assistant.enabled' => $previousAiEnabled]);
            $support->clear();
            $context->clear();
            if ($operator !== null) {
                Auth::setUser($operator);
            } else {
                Auth::forgetUser();
            }
        }
    }

    private function productReadUpdate(Request $request): bool
    {
        if (! $request->isMethod('POST') || ! str_ends_with((string) $request->route()?->getName(), 'livewire.update') || strlen($request->getContent()) > 65536) {
            return false;
        }
        $components = $request->input('components');
        if (! is_array($components) || count($components) !== 1 || ! isset($components[0]) || ! is_array($components[0])) {
            return false;
        }
        $component = $components[0];
        if (! is_string($component['snapshot'] ?? null) || ! is_array($component['updates'] ?? null) || ! is_array($component['calls'] ?? null) || count($component['calls']) > 10) {
            return false;
        }
        try {
            $snapshot = json_decode($component['snapshot'], true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($snapshot) || ! is_array($snapshot['data'] ?? null) || ! is_array($snapshot['memo'] ?? null) || ! is_string($snapshot['checksum'] ?? null)
                || ! preg_match('/^[a-f0-9]{64}$/D', $snapshot['checksum']) || ($snapshot['memo']['name'] ?? null) !== 'pages.products.product-index' || ($snapshot['memo']['path'] ?? null) !== 'products') {
                return false;
            }
            Checksum::verify($snapshot);
        } catch (\Throwable) {
            return false;
        }
        foreach ($component['updates'] as $field => $value) {
            if (! $this->safeProductFilter((string) $field, $value)) {
                return false;
            }
        }
        foreach ($component['calls'] as $call) {
            if (! is_array($call) || ! is_array($call['params'] ?? null) || ($call['path'] ?? '') !== '') {
                return false;
            }
            $method = $call['method'] ?? null;
            $params = $call['params'];
            $allowed = match ($method) {
                '$refresh', '$commit', 'clearCameraBarcode' => $params === [],
                'scanCameraBarcode' => count($params) === 1 && is_string($params[0] ?? null) && mb_strlen($params[0]) <= 128,
                '$set' => count($params) === 2 && is_string($params[0] ?? null) && $this->safeProductFilter($params[0], $params[1]),
                'gotoPage' => count($params) >= 1 && count($params) <= 2 && is_int($params[0] ?? null) && $params[0] > 0 && $params[0] <= 100000 && ($params[1] ?? 'page') === 'page',
                'nextPage', 'previousPage' => $params === [] || $params === ['page'],
                default => false,
            };
            if (! $allowed) {
                return false;
            }
        }

        return true;
    }

    private function safeProductFilter(string $field, mixed $value): bool
    {
        return match ($field) {
            'search' => is_string($value) && mb_strlen($value) <= 100,
            'cameraBarcode' => $value === null || (is_string($value) && mb_strlen($value) <= 128),
            'categoryFilter', 'brandFilter' => is_string($value) && preg_match('/^\d{0,18}$/D', $value) === 1,
            'statusFilter' => in_array($value, ['all', 'active', 'inactive'], true),
            'lowStockOnly', 'expiryOnly' => is_bool($value),
            default => false,
        };
    }
}
