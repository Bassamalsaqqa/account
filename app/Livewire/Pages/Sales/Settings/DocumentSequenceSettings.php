<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Sales\Settings;

use App\Models\DocumentSequence;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class DocumentSequenceSettings extends Component
{
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
        $company = $context->company();
        $user = auth()->user();

        if (! $user->hasRole(['Owner', 'Administrator'])) {
            abort(403, 'Unauthorized.');
        }

        $this->loadSequences();
    }

    public function loadSequences(): void
    {
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
        $company = app(CompanyContext::class)->company();
        $item = $this->sequences[$index] ?? null;
        if (! $item) {
            return;
        }

        $seq = DocumentSequence::where('company_id', $company->id)->findOrFail($item['id']);
        $seq->update([
            'prefix' => strtoupper(trim($item['prefix'])),
            'padding' => max(1, min(10, (int) $item['padding'])),
            'reset_policy' => in_array($item['reset_policy'], ['yearly', 'never'], true) ? $item['reset_policy'] : 'yearly',
        ]);

        session()->flash('success', __('sales.updated_successfully'));
        $this->loadSequences();
    }

    public function render(): View
    {
        return view('livewire.pages.sales.settings.document-sequence-settings');
    }
}
