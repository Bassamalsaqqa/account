<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5D;

use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\PurchaseReturn;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Purchasing\PurchaseReturnIssueCapability;
use App\Services\Purchasing\PurchaseReturnPostingScope;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;

class PurchaseReturnSecurityTest extends Phase5DTestCase
{
    /** Scenario 60: wrong tenant */
    public function test_scenario_60_wrong_tenant(): void
    {
        $purchase = $this->createAndPostPurchase();
        $draft = $this->createReturnDraft($purchase);

        app(CompanyContext::class)->clear();

        $this->expectException(AuthorizationException::class);
        $this->postReturn($draft);
    }

    /** Scenario 61: inactive membership */
    public function test_scenario_61_inactive_membership(): void
    {
        $purchase = $this->createAndPostPurchase();
        $draft = $this->createReturnDraft($purchase);

        DB::table('company_user')
            ->where('company_id', $this->company->id)
            ->where('user_id', $this->owner->id)
            ->update(['status' => 'inactive']);

        $this->expectException(AuthorizationException::class);
        $this->postReturn($draft);
    }

    /** Scenario 62: guest */
    public function test_scenario_62_guest(): void
    {
        $purchase = $this->createAndPostPurchase();
        $draft = $this->createReturnDraft($purchase);

        auth()->forgetUser();

        $this->expectException(AuthorizationException::class);
        $this->postReturn($draft);
    }

    /** Scenario 63: mismatched actor */
    public function test_scenario_63_mismatched_actor(): void
    {
        $purchase = $this->createAndPostPurchase();
        $draft = $this->createReturnDraft($purchase);

        $otherUser = User::factory()->create();
        $this->actingAs($otherUser);

        $this->expectException(AuthorizationException::class);
        $this->postReturn($draft, $this->owner);
    }

    /** Scenario 64: missing purchasing.return.manage */
    public function test_scenario_64_missing_purchasing_return_manage(): void
    {
        $purchase = $this->createAndPostPurchase();
        $draft = $this->createReturnDraft($purchase);

        $role = Role::where('company_id', $this->company->id)->where('name', 'Owner')->firstOrFail();
        $role->revokePermissionTo('purchasing.return.manage');

        $this->expectException(AuthorizationException::class);
        $this->postReturn($draft);
    }

    /** Scenario 65: missing purchasing.cost.view */
    public function test_scenario_65_missing_purchasing_cost_view(): void
    {
        $purchase = $this->createAndPostPurchase();
        $draft = $this->createReturnDraft($purchase);

        $role = Role::where('company_id', $this->company->id)->where('name', 'Owner')->firstOrFail();
        $role->revokePermissionTo('purchasing.cost.view');

        $this->expectException(AuthorizationException::class);
        $this->postReturn($draft);
    }

    /** Scenario 66: no inventory.stock.adjust permission needed */
    public function test_scenario_66_no_inventory_stock_adjust_permission_needed(): void
    {
        $purchase = $this->createAndPostPurchase();
        $draft = $this->createReturnDraft($purchase);

        $actor = User::factory()->create(['locale' => 'ar']);
        $this->company->users()->attach($actor->id, ['status' => 'active', 'is_owner' => false, 'joined_at' => now()]);
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'ReturnPoster', 'guard_name' => 'web']);
        $role->syncPermissions(['purchasing.return.manage', 'purchasing.cost.view']);
        $actor->assignRole($role);

        $this->activate($actor);
        $this->assertFalse($actor->hasPermissionTo('inventory.stock.adjust'));

        $posted = $this->postReturn($draft, $actor);
        $this->assertSame((int) $actor->id, (int) $posted->posted_by);
        $this->assertSame(PurchaseReturn::STATUS_POSTED, $posted->status);
    }

    /** Scenario 67: generic TYPE_PURCHASE_RETURN rejected */
    public function test_scenario_67_generic_type_purchase_return_rejected(): void
    {
        $purchase = $this->createAndPostPurchase();
        $receipt = StockMovement::where('company_id', $this->company->id)->firstOrFail();

        $command = new StockMovementCommand(
            companyId: (int) $this->company->id,
            movementType: StockMovement::TYPE_PURCHASE_RETURN,
            movementDate: '2026-10-04',
            lines: [
                new StockMovementLineCommand(
                    productId: (int) $this->product->id,
                    warehouseId: (int) $this->warehouse->id,
                    quantity: Quantity::of(1),
                    unitId: (int) $this->unit->unit_id,
                    originalMovementId: (int) $receipt->id,
                ),
            ],
            sourceType: 'purchase_return',
            sourceId: 1,
            idempotencyKey: 'test_generic_return_reject',
            createdBy: (int) $this->owner->id,
            sourceLineId: 1
        );

        $this->expectException(InvalidInventoryMovementException::class);
        $this->expectExceptionMessage('canonical Purchase Return posting');

        app(InventoryMovementService::class)->record($command);
    }

    /** Scenario 68: arbitrary DB transaction dedicated issue rejected */
    public function test_scenario_68_arbitrary_db_transaction_dedicated_issue_rejected(): void
    {
        $purchase = $this->createAndPostPurchase();
        $receipt = StockMovement::where('company_id', $this->company->id)->firstOrFail();

        $command = new StockMovementCommand(
            companyId: (int) $this->company->id,
            movementType: StockMovement::TYPE_PURCHASE_RETURN,
            movementDate: '2026-10-04',
            lines: [
                new StockMovementLineCommand(
                    productId: (int) $this->product->id,
                    warehouseId: (int) $this->warehouse->id,
                    quantity: Quantity::of(1),
                    unitId: (int) $this->unit->unit_id,
                    originalMovementId: (int) $receipt->id,
                ),
            ],
            sourceType: 'purchase_return',
            sourceId: 1,
            idempotencyKey: 'test_arbitrary_tx_return_reject',
            createdBy: (int) $this->owner->id,
            sourceLineId: 1
        );

        $this->expectException(InvalidInventoryMovementException::class);
        $this->expectExceptionMessage('canonical posting capability');

        DB::transaction(fn () => app(InventoryMovementService::class)->recordPurchaseReturnIssue($command));
    }

    public static function capabilityMismatches(): array
    {
        return [['return'], ['company'], ['actor'], ['forged capability'], ['authenticated actor'], ['company context']];
    }

    /** Scenario 69: capability cannot cross Return/originalPurchase/company/actor/connection */
    #[DataProvider('capabilityMismatches')]
    public function test_scenario_69_capability_cannot_cross_bound_identity(string $case): void
    {
        $purchase = $this->createAndPostPurchase();
        $draft1 = $this->createReturnDraft($purchase);
        $draft2 = $this->createReturnDraft($purchase);

        $receipt = StockMovement::where('company_id', $this->company->id)->firstOrFail();
        $line = $draft1->lines->first();

        $command = new StockMovementCommand(
            companyId: (int) $this->company->id,
            movementType: StockMovement::TYPE_PURCHASE_RETURN,
            movementDate: '2026-10-04',
            lines: [
                new StockMovementLineCommand(
                    productId: (int) $this->product->id,
                    warehouseId: (int) $this->warehouse->id,
                    quantity: Quantity::of(1),
                    unitId: (int) $this->unit->unit_id,
                    originalMovementId: (int) $receipt->id,
                ),
            ],
            sourceType: 'purchase_return',
            sourceId: (int) ($case === 'return' ? $draft2->id : $draft1->id),
            idempotencyKey: 'test_mismatch_key_'.$case,
            createdBy: (int) $this->owner->id,
            sourceLineId: (int) $line->id
        );

        $this->withinTestReturnPostingScope($draft1, function ($capability) use ($case, $command): void {
            if ($case === 'company') {
                $command = new StockMovementCommand(
                    companyId: $command->companyId + 999,
                    movementType: $command->movementType,
                    movementDate: $command->movementDate,
                    lines: $command->lines,
                    sourceType: $command->sourceType,
                    sourceId: $command->sourceId,
                    idempotencyKey: $command->idempotencyKey,
                    createdBy: $command->createdBy,
                    sourceLineId: $command->sourceLineId
                );
            } elseif ($case === 'actor') {
                $command = new StockMovementCommand(
                    companyId: $command->companyId,
                    movementType: $command->movementType,
                    movementDate: $command->movementDate,
                    lines: $command->lines,
                    sourceType: $command->sourceType,
                    sourceId: $command->sourceId,
                    idempotencyKey: $command->idempotencyKey,
                    createdBy: $command->createdBy + 999,
                    sourceLineId: $command->sourceLineId
                );
            } elseif ($case === 'authenticated actor') {
                auth()->setUser(new User);
            } elseif ($case === 'company context') {
                app(CompanyContext::class)->clear();
            }

            try {
                app(InventoryMovementService::class)->recordPurchaseReturnIssue(
                    $command,
                    $case === 'forged capability' ? new PurchaseReturnIssueCapability : $capability
                );
                $this->fail('Mismatched capability accepted.');
            } catch (InvalidInventoryMovementException $e) {
                $this->assertStringContainsString('canonical posting capability', $e->getMessage());
            } finally {
                $this->activate($this->owner);
            }
        });
    }

    /** Scenario 70: expired/rollback capability invalid */
    public function test_scenario_70_expired_or_rollback_capability_invalid(): void
    {
        $purchase = $this->createAndPostPurchase();
        $draft = $this->createReturnDraft($purchase);
        $receipt = StockMovement::where('company_id', $this->company->id)->firstOrFail();
        $line = $draft->lines->first();

        $command = new StockMovementCommand(
            companyId: (int) $this->company->id,
            movementType: StockMovement::TYPE_PURCHASE_RETURN,
            movementDate: '2026-10-04',
            lines: [
                new StockMovementLineCommand(
                    productId: (int) $this->product->id,
                    warehouseId: (int) $this->warehouse->id,
                    quantity: Quantity::of(1),
                    unitId: (int) $this->unit->unit_id,
                    originalMovementId: (int) $receipt->id,
                ),
            ],
            sourceType: 'purchase_return',
            sourceId: (int) $draft->id,
            idempotencyKey: 'test_expired_cap_key',
            createdBy: (int) $this->owner->id,
            sourceLineId: (int) $line->id
        );

        $capturedCapability = null;
        $this->withinTestReturnPostingScope($draft, function ($capability) use (&$capturedCapability): void {
            $capturedCapability = $capability;
            $this->assertTrue(app(PurchaseReturnPostingScope::class)->isActive($capability));
        });

        // Outside the scope: capability must be inactive
        $this->assertFalse(app(PurchaseReturnPostingScope::class)->isActive($capturedCapability));

        // Attempting to reuse it in a fresh transaction fails
        $this->expectException(InvalidInventoryMovementException::class);
        $this->expectExceptionMessage('canonical posting capability');

        DB::transaction(function () use ($command, $capturedCapability): void {
            app(InventoryMovementService::class)->recordPurchaseReturnIssue($command, $capturedCapability);
        });
    }

    /** C2-1: Completion capability cannot cross returns or attach forged/unsaved movements */
    public function test_c2_1_completion_capability_cannot_cross_return_or_attach_unpersisted_movement(): void
    {
        $purchase = $this->createAndPostPurchase();
        $draftA = $this->createReturnDraft($purchase);
        $draftB = $this->createReturnDraft($purchase);
        $lineB = $draftB->lines->first();
        $originalReceipt = $purchase->lines->first()->stock_movement_id;
        $before = $this->snapshotState();
        $error = null;

        try {
            $this->withinTestReturnPostingScope($draftA, function ($capability) use ($draftB, $lineB, $originalReceipt) {
                $forged = new StockMovement;
                $forged->id = $originalReceipt;
                $forged->company_id = $this->company->id;
                $forged->movement_type = StockMovement::TYPE_PURCHASE_RETURN;
                $forged->source_type = 'purchase_return';
                $forged->source_id = $draftB->id;
                $forged->source_line_id = $lineB->id;
                $forged->product_id = $this->product->id;
                $forged->warehouse_id = $this->warehouse->id;
                $lineB->completeCanonicalReturn($forged, '999', '999', '999', $this->owner, $capability);
            });
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'Capability for Return A completed Return B line using fake unsaved movement metadata.');
        $this->assertSame($before, $this->snapshotState());
    }

    /** C2-2: Dedicated stock issue API rejects command quantity outside persisted intent */
    public function test_c2_2_capability_does_not_authorize_command_quantity_outside_persisted_intent(): void
    {
        $purchase = $this->createAndPostPurchase();
        $draft = $this->createReturnDraft($purchase);
        $line = $draft->lines->first();
        $allocation = $line->allocations->first();
        $before = $this->snapshotState();
        $error = null;

        try {
            $this->withinTestReturnPostingScope($draft, function ($capability) use ($draft, $line, $allocation) {
                app(InventoryMovementService::class)->recordPurchaseReturnIssue(new StockMovementCommand(
                    companyId: $this->company->id,
                    movementType: StockMovement::TYPE_PURCHASE_RETURN,
                    movementDate: $draft->return_date->format('Y-m-d'),
                    lines: [
                        new StockMovementLineCommand(
                            productId: $this->product->id,
                            warehouseId: $this->warehouse->id,
                            quantity: Quantity::of('2'),
                            unitId: $this->product->base_unit_id,
                            originalMovementId: $allocation->original_stock_movement_id
                        ),
                    ],
                    sourceType: 'purchase_return',
                    sourceId: $draft->id,
                    idempotencyKey: 'sec-wrong-intent-qty',
                    createdBy: $this->owner->id,
                    sourceLineId: $line->id
                ), $capability);
            });
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'Dedicated API accepted qty 2 under capability for persisted qty 1 Return.');
        $this->assertSame($before, $this->snapshotState());
    }

    /** C2-2: Dedicated stock issue API rejects caller-supplied unit cost or value delta */
    public function test_c2_2_capability_rejects_caller_supplied_cost_or_value(): void
    {
        $purchase = $this->createAndPostPurchase();
        $draft = $this->createReturnDraft($purchase);
        $line = $draft->lines->first();
        $allocation = $line->allocations->first();
        $before = $this->snapshotState();
        $error = null;

        try {
            $this->withinTestReturnPostingScope($draft, function ($capability) use ($draft, $line, $allocation) {
                app(InventoryMovementService::class)->recordPurchaseReturnIssue(new StockMovementCommand(
                    companyId: $this->company->id,
                    movementType: StockMovement::TYPE_PURCHASE_RETURN,
                    movementDate: $draft->return_date->format('Y-m-d'),
                    lines: [
                        new StockMovementLineCommand(
                            productId: $this->product->id,
                            warehouseId: $this->warehouse->id,
                            quantity: Quantity::of('1'),
                            unitId: $this->product->base_unit_id,
                            unitCostBase: '5.000000',
                            originalMovementId: $allocation->original_stock_movement_id
                        ),
                    ],
                    sourceType: 'purchase_return',
                    sourceId: $draft->id,
                    idempotencyKey: 'sec-caller-cost',
                    createdBy: $this->owner->id,
                    sourceLineId: $line->id
                ), $capability);
            });
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'Dedicated API accepted caller-supplied unit cost.');
        $this->assertSame($before, $this->snapshotState());
    }
}
