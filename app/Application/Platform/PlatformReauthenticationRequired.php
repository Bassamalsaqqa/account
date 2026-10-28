<?php

declare(strict_types=1);

namespace App\Application\Platform;

use Illuminate\Auth\Access\AuthorizationException;

final class PlatformReauthenticationRequired extends AuthorizationException {}
