<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessWorkflow;

use App\Exceptions\IdempotencyConflictException;
use App\Models\Customer;
use App\Models\PostingBatch;
use App\Models\Vendor;
use App\Services\Audit\AuditService;
use App\Services\Sales\QuickPartyCreation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Phase5E\Phase5ETestCase;

final class QuickPartyCreationTest extends Phase5ETestCase
{
    public function test_same_request_replays_one_customer_even_when_cache_is_cleared(): void
    {
        $key = (string) Str::uuid();
        $data = ['name_ar' => 'عميل جديد', 'name_en' => 'New Customer', 'active' => true];
        $before = PostingBatch::count();
        $first = app(QuickPartyCreation::class)->execute($this->company, $this->owner, 'customer', $key, $data, 'sales.invoice.create');
        cache()->flush();
        $again = app(QuickPartyCreation::class)->execute($this->company, $this->owner, 'customer', $key, $data, 'sales.invoice.create');
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, Customer::where('name_ar', 'عميل جديد')->count());
        $this->assertSame(1, DB::table('party_creation_requests')->where('request_key', $key)->count());
        $this->assertSame($before, PostingBatch::count());
    }

    public function test_changed_payload_with_same_key_is_rejected(): void
    {
        $key = (string) Str::uuid();
        app(QuickPartyCreation::class)->execute($this->company, $this->owner, 'vendor', $key, ['name_ar' => 'مورد جديد'], 'purchasing.purchase.create');
        $before = Vendor::count();
        try {
            app(QuickPartyCreation::class)->execute($this->company, $this->owner, 'vendor', $key, ['name_ar' => 'مورد مختلف'], 'purchasing.purchase.create');
            $this->fail('Changed payload was accepted.');
        } catch (IdempotencyConflictException) {
            $this->assertSame($before, Vendor::count());
        }
    }

    public function test_failure_after_receipt_insertion_rolls_back_customer_and_receipt(): void
    {
        $key = (string) Str::uuid();
        $before = Customer::count();
        $audit = \Mockery::mock(AuditService::class);
        $audit->shouldReceive('log')->once()->andThrow(new \RuntimeException('Injected final audit failure'));
        $this->app->instance(AuditService::class, $audit);
        try {
            app(QuickPartyCreation::class)->execute($this->company, $this->owner, 'customer', $key, ['name_ar' => 'يجب التراجع'], 'sales.invoice.create');
            $this->fail('Injected failure was not raised.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected final audit failure', $exception->getMessage());
            $this->assertSame($before, Customer::count());
            $this->assertSame(0, DB::table('party_creation_requests')->where('request_key', $key)->count());
        }
    }

    public function test_master_manage_permission_alone_cannot_create_from_a_trade_form(): void
    {
        $actor = $this->customActor(['customers.manage']);
        $this->activate($actor);
        $this->expectException(AuthorizationException::class);
        app(QuickPartyCreation::class)->execute($this->company, $actor, 'customer', (string) Str::uuid(), ['name_ar' => 'Denied'], 'sales.invoice.create');
    }

    public function test_replay_rechecks_fresh_permissions(): void
    {
        $key = (string) Str::uuid();
        $data = ['name_ar' => 'Created before revocation'];
        app(QuickPartyCreation::class)->execute($this->company, $this->owner, 'customer', $key, $data, 'sales.invoice.create');
        $this->owner->roles()->firstOrFail()->revokePermissionTo('customers.manage');
        $this->expectException(AuthorizationException::class);
        app(QuickPartyCreation::class)->execute($this->company, $this->owner, 'customer', $key, $data, 'sales.invoice.create');
    }
}
