<?php

declare(strict_types=1);

namespace App\Services\Sales;

use InvalidArgumentException;

final class ManagedPublicUrl
{
    public function make(string $kind, string $token): string
    {
        if (! in_array($kind, ['share', 'catalog'], true) || ! preg_match('/^[A-Za-z0-9]{40}$/D', $token)) {
            throw new InvalidArgumentException('Invalid managed destination.');
        }
        $base = rtrim((string) config('app.url'), '/');
        $parts = parse_url($base);
        if (! is_array($parts) || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['scheme'] ?? '', app()->environment('local', 'testing') ? ['http', 'https'] : ['https'], true)) {
            throw new InvalidArgumentException('A trusted HTTPS application URL is required.');
        }

        return $base.'/'.$kind.'/'.$token;
    }
}
