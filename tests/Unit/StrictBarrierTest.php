<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\StrictBarrier;

final class StrictBarrierTest extends TestCase
{
    public function test_matching_ack(): void
    {
        $barrier = new StrictBarrier;
        $this->assertTrue($barrier->observe('select * from `users` where id = ? for update', 'users'));
        $barrier->requireHeld();
    }

    public function test_wrong_table_cannot_authorize_effects(): void
    {
        $barrier = new StrictBarrier;
        $this->assertFalse($barrier->observe('select * from `company_users` where id = ? for update', 'users'));
        $this->expectException(RuntimeException::class);
        $barrier->requireHeld();
    }

    public function test_missing_peer_deadline(): void
    {
        $this->expectException(RuntimeException::class);
        StrictBarrier::await(fn () => false, fn () => true, 1);
    }

    public function test_crashed_peer(): void
    {
        $this->expectException(RuntimeException::class);
        StrictBarrier::await(fn () => false, fn () => false);
    }

    public function test_missing_hold_negative_control(): void
    {
        $this->expectException(RuntimeException::class);
        (new StrictBarrier)->requireHeld();
    }
}
