<?php

declare(strict_types=1);

/** APP_ENV=testing, PHASE8_ALLOW_DISPOSABLE_DB=1 and PHASE8_TEST_DB_{HOST,PORT,USERNAME,PASSWORD} must be explicitly supplied. */
require __DIR__.'/../../vendor/autoload.php';

use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;

$schema = null;
$configPath = null;
$exitCode = 1;
try {
    DisposableMariaDbSchema::testSettings();
    $args = array_slice($argv, 1);
    if (($args[0] ?? null) === '--') {
        array_shift($args);
    }
    if ($args === []) {
        throw new RuntimeException('Supply a PHPUnit test target after --.');
    }
    foreach ($args as $arg) {
        if (str_starts_with($arg, '-c') || str_starts_with($arg, '--configuration') || str_starts_with($arg, '--bootstrap') || $arg === '--no-configuration') {
            throw new RuntimeException('Cannot override the isolated test configuration.');
        }
    }
    $schema = DisposableMariaDbSchema::create();
    $schema->migrate();
    $root = dirname(__DIR__, 2);
    $xml = new DOMDocument;
    $xml->load($root.'/phpunit.xml');
    $xml->documentElement->setAttribute('bootstrap', str_replace('\\', '/', $root).'/vendor/autoload.php');
    $xpath = new DOMXPath($xml);
    foreach ($xpath->query('//directory') as $node) {
        $node->nodeValue = str_replace('\\', '/', $root).'/'.$node->nodeValue;
    }
    $environment = $schema->environment();
    foreach (DisposableMariaDbSchema::testSettings() as $key => $value) {
        $environment['PHASE8_TEST_DB_'.$key] = $value;
    }
    foreach ($environment as $key => $value) {
        $node = $xpath->query('//php/env[@name="'.$key.'"]')->item(0);
        if ($node === null) {
            $node = $xml->createElement('env');
            $xpath->query('//php')->item(0)->appendChild($node);
        }
        $node->setAttribute('name', $key);
        $node->setAttribute('value', $value);
        $node->setAttribute('force', 'true');
    }
    $configPath = tempnam(sys_get_temp_dir(), 'p8_phpunit_');
    if ($configPath === false) {
        throw new RuntimeException('Cannot create private test configuration.');
    }
    chmod($configPath, 0600);
    $xml->save($configPath);
    fwrite(STDOUT, 'Owned primary test schema: '.$schema->schemaName().PHP_EOL);
    $process = new Process(array_merge([PHP_BINARY, 'vendor/bin/phpunit', '-c', $configPath], $args), $root, $environment);
    $process->setTimeout(900)->run(static function (string $type, string $buffer): void {
        fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
    });
    $exitCode = $process->getExitCode() ?? 1;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
} finally {
    if (is_string($configPath) && is_file($configPath)) {
        unlink($configPath);
    }
    if ($schema !== null) {
        $schema->drop();
        fwrite(STDOUT, 'Owned primary schema cleaned.'.PHP_EOL);
    }
}
exit($exitCode);
