<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class PrivateDocumentResponse
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $sourcePermission): Response
    {
        $response = $next($request);
        // Recheck fresh authority after potentially slow rendering, before delivery.
        DB::transaction(function () use ($sourcePermission): void {
            $context = app(CompanyContext::class);
            foreach (['sales.document.pdf', $sourcePermission] as $permission) {
                app(SalesActorGuard::class)->lockAndAuthorize((int) $context->companyId(), auth()->user(), $permission);
            }
            if ($sourcePermission === 'sales.statement.view') {
                app(SalesActorGuard::class)->lockAndAuthorize((int) $context->companyId(), auth()->user(), 'customers.statement.view');
            }
        });
        foreach (['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff'] as $header => $value) {
            $response->headers->set($header, $value);
        }
        if ($response->headers->get('Content-Type') === 'application/pdf') {
            preg_match('/filename="([^"]*)"/', (string) $response->headers->get('Content-Disposition'), $match);
            $filename = substr((string) preg_replace('/[^A-Za-z0-9_.-]/', '_', $match[1] ?? 'document.pdf'), 0, 160);
            $response->headers->set('Content-Disposition', ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$filename.'"');
        }

        return $response;
    }
}
