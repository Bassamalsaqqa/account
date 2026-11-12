<?php

declare(strict_types=1);

require_once __DIR__.'/../../../vendor/autoload.php';

use Tests\Support\Pilot\PilotServer;
use Tests\Support\Pilot\PilotSmokeTest;

$command = $argv[1] ?? 'run';
$stateDir = $argv[2] ?? throw new RuntimeException('An explicit owned private state directory is required.');

switch ($command) {
    case 'start':
        $state = PilotServer::start($stateDir);
        echo json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        break;

    case 'test':
        $smoke = new PilotSmokeTest($stateDir);
        $results = $smoke->runAll();
        echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        exit(count(array_filter($results, fn ($r) => $r['status'] !== 'PASS')) > 0 ? 1 : 0);

    case 'stop':
        $dropSchema = in_array('--drop-schema', $argv, true);
        PilotServer::stop($stateDir, $dropSchema);
        echo 'Pilot server stopped successfully. Schema dropped: '.($dropSchema ? 'yes' : 'no')."\n";
        break;

    case 'run':
    default:
        fwrite(STDOUT, "Starting pilot server...\n");
        $state = PilotServer::start($stateDir);
        $baseUrl = $state['baseUrl'] ?? $state['base_url'];
        fwrite(STDOUT, "Server started at {$baseUrl} (Schema: {$state['schema_name']})\n");
        fwrite(STDOUT, "Running real HTTPS network smoke tests...\n");
        $smoke = new PilotSmokeTest($stateDir);
        $results = $smoke->runAll();
        $allPassed = true;
        foreach ($results as $id => $res) {
            $pass = ($res['status'] === 'PASS');
            fwrite(STDOUT, sprintf("[%s] %s: %s\n", $pass ? 'PASS' : 'FAIL', $id, $res['details']));
            if (! $pass) {
                $allPassed = false;
            }
        }
        if (! $allPassed) {
            fwrite(STDERR, "Some smoke tests failed!\n");
            exit(1);
        }
        fwrite(STDOUT, "All smoke tests PASSED!\n");
        break;
}
