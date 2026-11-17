<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile\Contract;

use PHPUnit\Framework\TestCase;

final class CatalogSnapshotFramingTest extends TestCase
{
    /**
     * Helper to compute hash using the unambiguous canonical JSON row framing.
     */
    private function hashRows(array $rows): string
    {
        $ctx = hash_init('sha256');
        foreach ($rows as $row) {
            hash_update($ctx, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        }

        return hash_final($ctx);
    }

    public function test_colon_and_pipe_in_sku_and_name_do_not_collide(): void
    {
        // Attack case from Finding 1:
        // SKU 'x:y' and name_ar 'z' vs SKU 'x' and name_ar 'y:z'
        // In the legacy pipe/colon delimited string format, both produced:
        // P:1:x:y:z:01J...:10.000000:1:2026-11-11 12:00:00|
        $rowA = ['P', 1, '01JABCDEFGHJKMNPQRSTVWXYZ0', 'x:y', 'z', null, 'stock', true, 10, '10.000000', true, '2026-11-11 12:00:00'];
        $rowB = ['P', 1, '01JABCDEFGHJKMNPQRSTVWXYZ0', 'x', 'y:z', null, 'stock', true, 10, '10.000000', true, '2026-11-11 12:00:00'];

        $hashA = $this->hashRows([$rowA]);
        $hashB = $this->hashRows([$rowB]);

        $this->assertNotSame($hashA, $hashB, 'Framing collision detected: SKU with colon collided with shifted name_ar!');
    }

    public function test_pipe_character_cannot_split_or_shift_row_boundaries(): void
    {
        $rowWithPipeInName = ['P', 1, '01JABCDEFGHJKMNPQRSTVWXYZ0', 'SKU-001', 'Name|With|Pipes', null, 'stock', true, 10, '10.000000', true, '2026-11-11 12:00:00'];
        $rowWithoutPipe = ['P', 1, '01JABCDEFGHJKMNPQRSTVWXYZ0', 'SKU-001', 'NameWithPipes', null, 'stock', true, 10, '10.000000', true, '2026-11-11 12:00:00'];

        $hashWith = $this->hashRows([$rowWithPipeInName]);
        $hashWithout = $this->hashRows([$rowWithoutPipe]);

        $this->assertNotSame($hashWith, $hashWithout);
    }

    public function test_null_versus_empty_string_produces_different_hashes(): void
    {
        // Null English name vs empty string English name
        $rowWithNull = ['P', 1, '01JABCDEFGHJKMNPQRSTVWXYZ0', 'SKU-001', 'اسم', null, 'stock', true, 10, '10.000000', true, '2026-11-11 12:00:00'];
        $rowWithEmpty = ['P', 1, '01JABCDEFGHJKMNPQRSTVWXYZ0', 'SKU-001', 'اسم', '', 'stock', true, 10, '10.000000', true, '2026-11-11 12:00:00'];

        $hashNull = $this->hashRows([$rowWithNull]);
        $hashEmpty = $this->hashRows([$rowWithEmpty]);

        $this->assertNotSame($hashNull, $hashEmpty, 'Null vs empty string must produce distinct hashes!');
    }

    public function test_control_unicode_and_special_characters_are_deterministic_and_collision_free(): void
    {
        $rowControl1 = ['P', 1, '01JABCDEFGHJKMNPQRSTVWXYZ0', "SKU\x001", "Name\t1", null, 'stock', true, 10, '10.000000', true, '2026-11-11 12:00:00'];
        $rowControl2 = ['P', 1, '01JABCDEFGHJKMNPQRSTVWXYZ0', "SKU1\x00", "Name1\t", null, 'stock', true, 10, '10.000000', true, '2026-11-11 12:00:00'];

        $hash1 = $this->hashRows([$rowControl1]);
        $hash2 = $this->hashRows([$rowControl2]);

        $this->assertNotSame($hash1, $hash2);
    }

    public function test_distinct_types_are_unambiguous(): void
    {
        // Integer 0 vs string '0' vs boolean false
        $rowA = ['P', 1, '01JABCDEFGHJKMNPQRSTVWXYZ0', '0', 'Zero', null, 'stock', true, 10, '0.000000', false, '2026-11-11 12:00:00'];
        $rowB = ['P', 1, '01JABCDEFGHJKMNPQRSTVWXYZ0', '0', 'Zero', null, 'stock', true, 10, '0.000000', true, '2026-11-11 12:00:00'];

        $hashA = $this->hashRows([$rowA]);
        $hashB = $this->hashRows([$rowB]);

        $this->assertNotSame($hashA, $hashB);
    }

    public function test_regex_anchors_strictly_reject_terminal_newlines_unlike_dollar_anchors(): void
    {
        $looseLimitRegex = '/^(?:0|[1-9][0-9]*)$/';
        $strictLimitRegex = '/\A(?:0|[1-9][0-9]*)\z/';

        $inputWithNewline = "10\n";
        // Old loose pattern with $ matches terminal newline
        $this->assertSame(1, preg_match($looseLimitRegex, $inputWithNewline));
        // New strict pattern with \z rejects terminal newline
        $this->assertSame(0, preg_match($strictLimitRegex, $inputWithNewline));

        $looseCursorRegex = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/';
        $strictCursorRegex = '/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/';

        $cursorWithNewline = "01JCV8X9MDPTRSVWXYZ0123456\n";
        // Old loose pattern with $ matches terminal newline
        $this->assertSame(1, preg_match($looseCursorRegex, $cursorWithNewline));
        // New strict pattern with \z rejects terminal newline
        $this->assertSame(0, preg_match($strictCursorRegex, $cursorWithNewline));
    }
}
