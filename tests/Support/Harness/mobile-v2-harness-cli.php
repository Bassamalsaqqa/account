<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Tests\Support\Harness\MobileV2HarnessCommand;

// CLI entrypoint for Mobile V2 Harness driver (tests/Support/Harness)
// Does not modify production application or console commands.

require __DIR__.'/../../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../../bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$console = new Symfony\Component\Console\Application('Mobile V2 Harness CLI', '1.0.0');
$command = new MobileV2HarnessCommand;
$command->setLaravel($app);
$console->addCommand($command);
$console->setDefaultCommand('mobile:v2-harness', true);

$status = $console->run();
exit($status);
