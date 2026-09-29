<?php

namespace Tests\Feature\Phase1;

use App\Actions\Company\CreateCompanyAction;
use App\Models\Company;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AuditServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AuditService $auditService;

    protected Company $company;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auditService = app(AuditService::class);
        $this->user = User::factory()->create();
        $this->company = app(CreateCompanyAction::class)->execute($this->user, [
            'name_ar' => 'شركة التدقيق',
        ]);
    }

    public function test_audit_service_logs_event_with_actor_and_metadata(): void
    {
        $event = $this->auditService->log(
            companyId: $this->company->id,
            eventKey: 'settings.test_event',
            summary: 'Test audit event summary',
            actorUserId: $this->user->id,
            meta: ['action' => 'test']
        );

        $this->assertDatabaseHas('audit_events', [
            'id' => $event->id,
            'company_id' => $this->company->id,
            'actor_user_id' => $this->user->id,
            'event_key' => 'settings.test_event',
            'summary' => 'Test audit event summary',
        ]);
        $this->assertNotNull($event->public_id);
    }

    public function test_audit_service_redacts_sensitive_keys(): void
    {
        $payload = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'super-secret-password-123',
            'password_confirmation' => 'super-secret-password-123',
            'two_factor_secret' => 'BASE32SECRETKEY',
            'two_factor_recovery_codes' => ['code1', 'code2'],
            'api_key' => 'sk_live_1234567890',
            'nested' => [
                'token' => 'jwt.token.here',
                'harmless' => 'visible',
            ],
        ];

        $redacted = $this->auditService->redact($payload);

        $this->assertSame('John Doe', $redacted['name']);
        $this->assertSame('john@example.com', $redacted['email']);
        $this->assertSame('[REDACTED]', $redacted['password']);
        $this->assertSame('[REDACTED]', $redacted['password_confirmation']);
        $this->assertSame('[REDACTED]', $redacted['two_factor_secret']);
        $this->assertSame('[REDACTED]', $redacted['two_factor_recovery_codes']);
        $this->assertSame('[REDACTED]', $redacted['api_key']);
        $this->assertSame('[REDACTED]', $redacted['nested']['token']);
        $this->assertSame('visible', $redacted['nested']['harmless']);
    }

    public function test_audit_event_is_immutable_and_cannot_be_updated(): void
    {
        $event = $this->auditService->log(
            companyId: $this->company->id,
            eventKey: 'settings.immutable_test',
            summary: 'Original summary',
            actorUserId: $this->user->id
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Audit events are immutable and cannot be updated.');

        $event->update(['summary' => 'Tampered summary']);
    }

    public function test_audit_event_is_immutable_and_cannot_be_deleted(): void
    {
        $event = $this->auditService->log(
            companyId: $this->company->id,
            eventKey: 'settings.immutable_delete_test',
            summary: 'Original summary',
            actorUserId: $this->user->id
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Audit events are immutable and cannot be deleted.');

        $event->delete();
    }
}
