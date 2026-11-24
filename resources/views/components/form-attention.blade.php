@props(['focus' => true])

@if ($errors->any())
    <div role="alert" tabindex="-1" data-form-attention
         @if ($focus) x-data x-init="$nextTick(() => requestAnimationFrame(() => { const form = $el.closest('[data-entry-form]') ?? $el.parentElement; const field = [...form.querySelectorAll('[aria-invalid=true]')].find(el => el.getClientRects().length && !el.disabled); (field ?? $el).focus(); (field ?? $el).scrollIntoView({ block: 'center', behavior: 'instant' }); }))" @endif
         {{ $attributes->class('rounded-control border border-danger/30 bg-danger-bg px-4 py-3 text-sm text-danger') }}>
        <ul class="space-y-1">
            @foreach (array_unique($errors->all()) as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
