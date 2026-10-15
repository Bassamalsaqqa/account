<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessWorkflow;

use App\Actions\Company\CreateCompanyAction;
use App\Livewire\Pages\Sales\PaymentForm;
use App\Models\Customer;
use App\Models\PostingBatch;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Livewire\Livewire;
use Tests\Feature\Phase5E\Phase5ETestCase;

final class TenantValidationTest extends Phase5ETestCase
{
    public function test_foreign_customer_is_rejected_as_a_field_error_before_receipt_posting(): void
    {
        app(CompanyContext::class)->clear();
        $foreignOwner = User::factory()->create();
        $other = app(CreateCompanyAction::class)->execute($foreignOwner, ['name_ar' => 'Foreign fixture']);
        app(CompanyContext::class)->setCompany($other, $foreignOwner);
        $this->actingAs($foreignOwner);
        $foreignCustomer = Customer::create(['company_id' => $other->id, 'name_ar' => 'Foreign customer', 'active' => true]);
        $this->activate($this->owner);
        $before = PostingBatch::count();
        Livewire::test(PaymentForm::class)->set('customer_id', $foreignCustomer->id)->set('money_account_id', $this->ilsCashAccount->id)
            ->set('amount', '10')->set('exchange_rate', '1')->set('payment_date', '2026-10-15')->call('save')
            ->assertHasErrors(['customer_id']);
        $this->assertSame($before, PostingBatch::count());
    }
}
