<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Sales\DocumentRenderer;
use App\Services\Sales\IssuedFinancialShares;
use App\Services\Sales\PdfRendererService;
use App\Services\Sales\PrintRenderer;
use App\Services\Sales\PublicShareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class PublicShareController extends Controller
{
    public function show(string $token, Request $request, PublicShareService $shareService): Response
    {
        $issued = app(IssuedFinancialShares::class);
        $ipKey = 'financial-ip:'.hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($ipKey, 60)) {
            return response()->view('sales.share-landing', ['unavailable' => true], 429);
        }
        RateLimiter::hit($ipKey, 60);
        try {
            $share = $issued->lookup($token);
            if ($share === null) {
                return response()->view('sales.share-landing', ['unavailable' => true], 404);
            }
            if ($request->isMethod('POST')) {
                $attemptKey = 'financial-open:'.hash('sha256', $share->public_id.'|'.$request->ip());
                if (RateLimiter::tooManyAttempts($attemptKey, 5)) {
                    return response()->view('sales.share-landing', ['unavailable' => true], 429);
                }
                RateLimiter::hit($attemptKey, 60);
            }
            if ($share->access_profile === null) {
                if ($request->query('format') !== null) {
                    return response()->view('sales.share-landing', ['unavailable' => true], 404);
                }
                $password = $request->isMethod('POST') ? $request->post('password') : null;
                $result = $shareService->resolvePublicShare($token, is_string($password) ? $password : null);
                if ($result['status'] === 'success') {
                    app()->setLocale($result['data']['document_locale']);
                }

                return response()->view('sales.public-share', ['token' => $token, 'result' => $result]);
            }
            $share = $issued->valid($share);
            if ($request->isMethod('POST')) {
                $password = $request->post('password');
                $issued->unlock($share, $request, is_string($password) && strlen($password) <= 128 ? $password : null);

                return redirect()->route('public.share', ['token' => $token]);
            }
            try {
                $share = $issued->authorized($share, $request);
            } catch (\InvalidArgumentException) {
                return response()->view('sales.share-landing', ['token' => $token, 'requiresPassword' => $share->password_hash !== null]);
            }
            $data = $issued->content($share);
            $format = $request->query('format');
            if ($format === 'pdf') {
                $renderKey = 'financial-pdf:'.hash('sha256', $share->public_id.'|'.$request->ip());
                if (RateLimiter::tooManyAttempts($renderKey, 5)) {
                    return response()->view('sales.share-landing', ['unavailable' => true], 429);
                }
                RateLimiter::hit($renderKey, 60);
                $response = response(app(PdfRendererService::class)->renderDocument($data, guest: true), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="document.pdf"']);
            } elseif ($format === 'json') {
                $response = response()->json($data->toArray());
            } elseif ($format === 'print') {
                $response = response(app(PrintRenderer::class)->render($data));
            } elseif ($format === null) {
                $base = '/share/'.$token;
                $response = response(app(DocumentRenderer::class)->html($data, printControls: true, publicLinks: ['view_pdf' => $base.'?format=pdf', 'download_pdf' => $base.'?format=pdf&download=1']));
            } else {
                return response()->view('sales.share-landing', ['unavailable' => true], 404);
            }
            // No read or alternate format can outlive revoke, expiry, source revision or password state.
            $issued->authorized($share, $request);
            $issued->recordView($share);

            return $response;
        } catch (\Throwable) {
            // Deliberately generic: exceptions can contain private source or cryptographic context.
            return response()->view('sales.share-landing', ['unavailable' => true], 404);
        }
    }
}
