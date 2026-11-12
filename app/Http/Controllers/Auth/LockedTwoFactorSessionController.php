<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;

final class LockedTwoFactorSessionController extends TwoFactorAuthenticatedSessionController
{
    public function store(TwoFactorLoginRequest $request)
    {
        if (! $request->session()->has('login.id')) {
            return parent::store($request);
        }

        return DB::transaction(function () use ($request) {
            // Same first lock as native issuance; Fortify validation and replacement
            // both see the freshly locked encrypted code set, including concurrent use.
            $fresh = User::whereKey($request->session()->get('login.id'))->lockForUpdate()->firstOrFail();
            $user = $request->challengedUser();
            $user->setRawAttributes($fresh->getAttributes(), true);

            return parent::store($request);
        });
    }
}
