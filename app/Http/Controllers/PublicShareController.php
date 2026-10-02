<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Sales\PublicShareService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicShareController extends Controller
{
    public function show(string $token, Request $request, PublicShareService $shareService): View
    {
        $password = $request->input('password');

        $result = $shareService->resolvePublicShare($token, $password ? (string) $password : null);

        if ($result['status'] === 'success') {
            app()->setLocale($result['data']['document_locale']);
        }

        return view('sales.public-share', [
            'token' => $token,
            'result' => $result,
        ]);
    }
}
