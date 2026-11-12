<?php

declare(strict_types=1);

namespace Tests\Support\Pilot;

use Symfony\Component\Process\Process;

final class PilotCertificateManager
{
    /**
     * Generates an owned ephemeral test CA and localhost certificate with SAN
     * appropriate for localhost and 127.0.0.1.
     * All private keys are set to mode 0600 in the owned 0700 state directory.
     *
     * @return array{ca_cert: string, ca_key: string, server_cert: string, server_key: string, mismatch_server_cert: string, mismatch_server_key: string, untrusted_server_cert: string, untrusted_server_key: string}
     */
    public static function generate(string $outputDir): array
    {
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0700, true);
        }
        chmod($outputDir, 0700);

        $caKey = $outputDir.DIRECTORY_SEPARATOR.'ca.key';
        $caCrt = $outputDir.DIRECTORY_SEPARATOR.'ca.crt';
        $serverKey = $outputDir.DIRECTORY_SEPARATOR.'server.key';
        $serverCsr = $outputDir.DIRECTORY_SEPARATOR.'server.csr';
        $serverCrt = $outputDir.DIRECTORY_SEPARATOR.'server.crt';
        $extFile = $outputDir.DIRECTORY_SEPARATOR.'server_ext.cnf';

        // 1. Generate Test CA (RSA 2048)
        $process = new Process([
            'openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
            '-keyout', $caKey,
            '-out', $caCrt,
            '-days', '30',
            '-subj', '/CN=Pilot-Ephemeral-Test-CA',
            '-addext', 'basicConstraints=critical,CA:TRUE',
            '-addext', 'keyUsage=critical,keyCertSign,cRLSign',
            '-addext', 'subjectKeyIdentifier=hash',
        ]);
        $process->mustRun();
        chmod($caKey, 0600);
        chmod($caCrt, 0644);

        // 2. Generate Server Key & CSR
        $process = new Process([
            'openssl', 'req', '-newkey', 'rsa:2048', '-nodes',
            '-keyout', $serverKey,
            '-out', $serverCsr,
            '-subj', '/CN=localhost',
        ]);
        $process->mustRun();
        chmod($serverKey, 0600);

        // 3. Write OpenSSL extension config with SAN for localhost and 127.0.0.1
        $extContent = "subjectAltName=DNS:localhost,IP:127.0.0.1\n"
            ."basicConstraints=CA:FALSE\n"
            ."keyUsage=digitalSignature,keyEncipherment\n"
            ."extendedKeyUsage=serverAuth\n";
        file_put_contents($extFile, $extContent, LOCK_EX);
        chmod($extFile, 0600);

        // 4. Sign Server Certificate with Test CA
        $process = new Process([
            'openssl', 'x509', '-req',
            '-in', $serverCsr,
            '-CA', $caCrt,
            '-CAkey', $caKey,
            '-CAcreateserial',
            '-out', $serverCrt,
            '-days', '30',
            '-extfile', $extFile,
        ]);
        $process->mustRun();
        chmod($serverCrt, 0644);

        // 5. Generate Mismatch Hostname Cert & Key (SAN does NOT include 127.0.0.1)
        $mismatchKey = $outputDir.DIRECTORY_SEPARATOR.'mismatch_server.key';
        $mismatchCsr = $outputDir.DIRECTORY_SEPARATOR.'mismatch_server.csr';
        $mismatchCrt = $outputDir.DIRECTORY_SEPARATOR.'mismatch_server.crt';
        $mismatchExt = $outputDir.DIRECTORY_SEPARATOR.'mismatch_ext.cnf';

        $process = new Process([
            'openssl', 'req', '-newkey', 'rsa:2048', '-nodes',
            '-keyout', $mismatchKey,
            '-out', $mismatchCsr,
            '-subj', '/CN=wrong.hostname.local',
        ]);
        $process->mustRun();
        chmod($mismatchKey, 0600);

        file_put_contents($mismatchExt, "subjectAltName=DNS:wrong.hostname.local\nbasicConstraints=CA:FALSE\n", LOCK_EX);
        chmod($mismatchExt, 0600);

        $process = new Process([
            'openssl', 'x509', '-req',
            '-in', $mismatchCsr,
            '-CA', $caCrt,
            '-CAkey', $caKey,
            '-CAcreateserial',
            '-out', $mismatchCrt,
            '-days', '30',
            '-extfile', $mismatchExt,
        ]);
        $process->mustRun();
        chmod($mismatchCrt, 0644);

        // 6. Generate Untrusted Self-Signed Certificate
        $untrustedKey = $outputDir.DIRECTORY_SEPARATOR.'untrusted_server.key';
        $untrustedCrt = $outputDir.DIRECTORY_SEPARATOR.'untrusted_server.crt';
        $process = new Process([
            'openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
            '-keyout', $untrustedKey,
            '-out', $untrustedCrt,
            '-days', '30',
            '-subj', '/CN=localhost',
            '-addext', 'subjectAltName=DNS:localhost,IP:127.0.0.1',
        ]);
        $process->mustRun();
        chmod($untrustedKey, 0600);
        chmod($untrustedCrt, 0644);

        // Clean up CSR and ext file
        @unlink($serverCsr);
        @unlink($extFile);
        @unlink($mismatchCsr);
        @unlink($mismatchExt);
        $srl = $outputDir.DIRECTORY_SEPARATOR.'ca.srl';
        @unlink($srl);

        return [
            'ca_cert' => $caCrt,
            'ca_key' => $caKey,
            'server_cert' => $serverCrt,
            'server_key' => $serverKey,
            'mismatch_server_cert' => $mismatchCrt,
            'mismatch_server_key' => $mismatchKey,
            'untrusted_server_cert' => $untrustedCrt,
            'untrusted_server_key' => $untrustedKey,
        ];
    }
}
