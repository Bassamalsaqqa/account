<?php

declare(strict_types=1);

namespace App\Application\AI;

final class AiAudioLimits
{
    /** Local runtime caps are measured at request time; this is not Hostinger certification.
     * @return array{max_bytes: int, max_seconds: int, upload_max_bytes: int, post_max_bytes: int, execution_seconds: int}
     */
    public function current(): array
    {
        $upload = $this->bytes((string) ini_get('upload_max_filesize'));
        $post = $this->bytes((string) ini_get('post_max_size'));
        $limit = min((int) config('ai_assistant.max_audio_bytes'), $upload, max(0, $post - 65536));

        return ['max_bytes' => $limit, 'max_seconds' => min((int) config('ai_assistant.max_audio_seconds'), max(0, intdiv($limit - 44, 96000))),
            'upload_max_bytes' => $upload, 'post_max_bytes' => $post, 'execution_seconds' => (int) ini_get('max_execution_time')];
    }

    private function bytes(string $setting): int
    {
        if (! preg_match('/\A(\d+)\s*([KMG]?)\z/i', trim($setting), $matches)) {
            return 0;
        }
        $number = (int) $matches[1];
        if ($number === 0) {
            return PHP_INT_MAX;
        }

        return $number * match (strtoupper($matches[2])) {
            'K' => 1024, 'M' => 1048576, 'G' => 1073741824, default => 1,
        };
    }
}
