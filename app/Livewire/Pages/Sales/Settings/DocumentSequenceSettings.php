<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Sales\Settings;

use App\Actions\Sales\UpdateDocumentSequenceAction;
use App\Models\DocumentSequence;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class DocumentSequenceSettings extends Component
{
    #[Locked]
    public int $settingsCompanyId;

    /**
     * @var list<array{
     *     id: int,
     *     document_type: string,
     *     prefix: string,
     *     year: ?int,
     *     next_number: int,
     *     padding: int,
     *     reset_policy: string,
     * }>
     */
    public array $sequences = [];

    public function mount(CompanyContext $context): void
    {
        $this->settingsCompanyId = (int) $context->companyId();
        $company = $context->company();
        $user = auth()->user();

        $this->authorizeSettings();

        $this->loadSequences();
    }

    public function loadSequences(): void
    {
        $this->authorizeSettings();
        $company = app(CompanyContext::class)->company();
        $seqs = DocumentSequence::where('company_id', $company->id)->get();

        $this->sequences = [];
        foreach ($seqs as $s) {
            $this->sequences[] = [
                'id' => $s->id,
                'document_type' => $s->document_type,
                'prefix' => $s->prefix,
                'year' => $s->year,
                'next_number' => $s->next_number,
                'padding' => $s->padding,
                'reset_policy' => $s->reset_policy,
            ];
        }
    }

    public function updateSequence(int $index): void
    {
        $this->authorizeSettings();
        $company = app(CompanyContext::class)->company();
        $item = $this->sequences[$index] ?? null;
        if (! $item) {
            return;
        }

        app(UpdateDocumentSequenceAction::class)->execute($company, auth()->user(), $item['id'], $item);

        session()->flash('success', __('sales.updated_successfully'));
        $this->loadSequences();
    }

    private function authorizeSettings(): void
    {
        try {
            DB::transaction(function (): void {
                app(SalesActorGuard::class)->lockAndAuthorize(
                    $this->settingsCompanyId, auth()->user(), 'settings.sequences.manage');
            });
        } catch (AuthorizationException $e) {
            abort(403);
        }
    }

    public function render(): View
    {
        $this->authorizeSettings();

        return view('livewire.pages.sales.settings.document-sequence-settings');
    }
}
