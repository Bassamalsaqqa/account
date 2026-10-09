<?php

declare(strict_types=1);

namespace App\Services\Catalogs;

use App\Models\PublicShare;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

final class CatalogAccess
{
    private function state(PublicShare $share): string
    {
        return hash('sha256', implode('|', [$share->public_id, $share->token_lookup_hash, $share->password_hash, $share->expires_at?->timestamp]));
    }

    public function unlock(PublicShare $share, Request $request): void
    {
        $password = $request->post('password');
        if (! $request->isMethod('POST') || ! $request->hasSession() || ! is_string($password) || strlen($password) > 128
            || $share->password_hash === null || ! Hash::check($password, $share->password_hash)) {
            throw new InvalidArgumentException('Unavailable catalog.');
        }
        $request->session()->put('catalog_unlock.'.$share->public_id, ['state' => $this->state($share), 'until' => min(now()->addMinutes(15)->timestamp, $share->expires_at->timestamp ?? PHP_INT_MAX)]);
    }

    public function allows(PublicShare $share, Request $request): bool
    {
        if ($share->password_hash === null) {
            return true;
        }
        $unlock = $request->hasSession() ? $request->session()->get('catalog_unlock.'.$share->public_id) : null;

        return is_array($unlock) && is_string($unlock['state'] ?? null) && ($unlock['until'] ?? 0) > now()->timestamp
            && hash_equals($this->state($share), $unlock['state']);
    }
}
