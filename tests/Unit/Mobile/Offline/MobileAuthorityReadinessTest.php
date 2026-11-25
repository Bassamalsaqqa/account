<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile\Offline;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class MobileAuthorityReadinessTest extends TestCase
{
    public function test_missing_revision_cannot_fall_back_to_password_only_authority(): void
    {
        DB::shouldReceive('table')->never();

        $this->assertUnavailable(new User);
    }

    public function test_revision_column_alone_does_not_enable_mobile_authority(): void
    {
        $this->mockMigrationRecord(false);

        $this->assertUnavailable($this->userWithRevision(0));
    }

    #[TestWith([0, ''])]
    #[TestWith([2, ':2'])]
    public function test_completed_installation_preserves_password_epoch_format(int $revision, string $suffix): void
    {
        $this->mockMigrationRecord(true);
        $user = $this->userWithRevision($revision);

        $this->assertSame('epoch-'.substr(hash('sha256', (string) $user->password), 0, 16).$suffix, $user->mobilePasswordEpoch());
    }

    private function userWithRevision(int $revision): User
    {
        return (new User)->setRawAttributes(['mobile_authority_revision' => $revision, 'password' => 'synthetic-password-hash']);
    }

    private function assertUnavailable(User $user): void
    {
        try {
            $user->mobilePasswordEpoch();
            $this->fail('Mobile authority was granted before installation completed.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }
    }

    private function mockMigrationRecord(bool $applied): void
    {
        config(['database.migrations.table' => 'test_migration_repository']);
        $query = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->once()->with('test_migration_repository')->andReturn($query);
        $query->shouldReceive('useWritePdo')->once()->andReturnSelf();
        $query->shouldReceive('where')->once()
            ->with('migration', '2026_11_25_100002_track_mobile_authority_and_offline_policy_revocation')->andReturnSelf();
        $query->shouldReceive('exists')->once()->andReturn($applied);
    }
}
