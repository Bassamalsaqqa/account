<?php

declare(strict_types=1);

namespace App\Application\AI;

final class PcmWave
{
    /** Validate actual uncompressed samples, never a claimed browser duration or filename. */
    public function seconds(string $bytes): int
    {
        $size = strlen($bytes);
        if ($size < 46 || $size > (int) config('ai_assistant.max_audio_bytes')
            || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 8) !== 'WAVEfmt '
            || substr($bytes, 36, 4) !== 'data') {
            throw new AssistantUnavailable('audio_invalid');
        }
        $header = unpack('Vriff/VformatSize/vformat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbits/VdataSize', substr($bytes, 4, 4).substr($bytes, 16, 20).substr($bytes, 40, 4));
        if ($header === false || $header['riff'] !== $size - 8 || $header['formatSize'] !== 16
            || $header['format'] !== 1 || $header['channels'] !== 1 || $header['bits'] !== 16
            || $header['blockAlign'] !== 2 || $header['sampleRate'] < 8000 || $header['sampleRate'] > 48000
            || $header['byteRate'] !== $header['sampleRate'] * 2
            || $header['dataSize'] !== $size - 44 || $header['dataSize'] % 2 !== 0
            || $header['dataSize'] > $header['byteRate'] * (int) config('ai_assistant.max_audio_seconds')) {
            throw new AssistantUnavailable('audio_invalid');
        }

        return intdiv($header['dataSize'] + $header['byteRate'] - 1, $header['byteRate']);
    }
}
