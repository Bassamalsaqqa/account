<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\Phase11ServiceProvider;
use App\Providers\Phase12ServiceProvider;
use App\Providers\VoltServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    Phase11ServiceProvider::class,
    Phase12ServiceProvider::class,
    VoltServiceProvider::class,
];
