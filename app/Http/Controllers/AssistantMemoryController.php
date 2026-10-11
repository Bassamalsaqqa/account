<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\AI\AiFormState;
use App\Application\AI\AssistantUnavailable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AssistantMemoryController
{
    public function __invoke(Request $request, AiFormState $memory): Response
    {
        $values = $request->validate(['token' => ['required', 'uuid']]);
        try {
            $memory->forgetForSession($request->user(), $values['token'], $request->session()->getId());
        } catch (AssistantUnavailable) {
            // Indistinguishable missing/foreign tokens; never expose form or tenant metadata.
        }

        return response()->noContent()->header('Cache-Control', 'private, no-store');
    }
}
