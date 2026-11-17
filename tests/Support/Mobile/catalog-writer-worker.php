<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\DisposableMariaDbSchema;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$dbName = $argv[1];
$productId = (int) $argv[2];
$newPrice = $argv[3];
$marker = $argv[4];

DisposableMariaDbSchema::assertPrimarySchema($dbName);
config(['database.connections.mysql.database' => $dbName]);
DB::purge('mysql');
DB::reconnect('mysql');

// Execute update in an independent process and commit
DB::table('products')->where('id', $productId)->update([
    'default_sale_price_base' => $newPrice,
    'updated_at' => now(),
]);

touch($marker);
echo json_encode(['status' => 200, 'updated_id' => $productId, 'price' => $newPrice], JSON_THROW_ON_ERROR);
