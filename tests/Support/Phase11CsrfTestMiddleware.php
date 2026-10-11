<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

class Phase11CsrfTestMiddleware extends PreventRequestForgery
{
    /**
     * Override runningUnitTests to disable the testing CSRF bypass in PHPUnit.
     */
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
