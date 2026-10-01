<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-extrabold text-text-primary flex items-center gap-2.5">
                <x-icon name="dashboard" class="w-6 h-6 text-primary" />
                <span>{{ __('inventory.units') }}</span>
            </h1>
            <p class="text-xs text-text-secondary mt-1">تعريف وإدارة وحدات القياس، الرموز، وإمكانية الكسور العشرية</p>
        </div>

        @if ($canManage)
            <button type="button" wire:click="openCreateModal"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-primary hover:bg-primary-hover text-white text-xs font-bold rounded-control shadow-button transition-colors">
                <x-icon name="plus" class="w-4 h-4" />
                <span>إضافة وحدة جديدة</span>
            </button>
        @endif
    </div>

    @if ($successMessage)
        <div class="p-3.5 rounded-control bg-success-bg border border-success/30 text-success text-xs font-semibold flex items-center justify-between">
            <span>{{ $successMessage }}</span>
            <button type="button" wire:click="$set('successMessage', null)" class="text-success hover:opacity-75">&times;</button>
        </div>
    @endif

    <x-card padding="p-0">
        <div class="overflow-x-auto">
            <table class="w-full text-xs text-right rtl:text-right ltr:text-left">
                <thead class="bg-surface-soft border-b border-border text-text-secondary font-bold">
                    <tr>
                        <th class="p-3.5">الرمز (Code)</th>
                        <th class="p-3.5">اسم الوحدة</th>
                        <th class="p-3.5">المختصر</th>
                        <th class="p-3.5 text-center">يقبل الكسور</th>
                        <th class="p-3.5 text-center">الخانات العشرية</th>
                        <th class="p-3.5 text-center">الحالة</th>
                        @if ($canManage)
                            <th class="p-3.5 text-center w-20"></th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($units as $unit)
                        <tr class="hover:bg-surface-soft/60 transition-colors">
                            <td class="p-3.5 font-mono dir-ltr font-bold text-text-primary">
                                {{ $unit->code }}
                            </td>
                            <td class="p-3.5">
                                <span class="font-bold text-text-primary block">{{ $unit->name_ar }}</span>
                                @if ($unit->name_en)
                                    <span class="text-[11px] text-text-muted dir-ltr block">{{ $unit->name_en }}</span>
                                @endif
                            </td>
                            <td class="p-3.5 text-text-secondary">
                                {{ $unit->symbol_ar ?? $unit->symbol_en ?? '—' }}
                            </td>
                            <td class="p-3.5 text-center">
                                @if ($unit->allow_fractions)
                                    <span class="text-success font-bold">&#10003; نعم</span>
                                @else
                                    <span class="text-text-muted">لا</span>
                                @endif
                            </td>
                            <td class="p-3.5 text-center font-mono text-text-secondary">
                                {{ $unit->decimal_places }}
                            </td>
                            <td class="p-3.5 text-center">
                                @if ($unit->active)
                                    <x-badge variant="success">{{ __('inventory.active') }}</x-badge>
                                @else
                                    <x-badge>{{ __('inventory.inactive') }}</x-badge>
                                @endif
                            </td>
                            @if ($canManage)
                                <td class="p-3.5 text-center">
                                    <button type="button" wire:click="openEditModal({{ $unit->id }})"
                                            class="text-primary hover:underline font-bold text-xs">
                                        تعديل
                                    </button>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canManage ? 7 : 6 }}" class="p-6 text-center text-text-muted">
                                لا توجد وحدات قياس مسجلة.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <!-- Create/Edit Modal -->
    @if ($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-xs p-4">
            <div class="bg-white rounded-card border border-border shadow-xl w-full max-w-md p-6 space-y-4">
                <div class="flex items-center justify-between border-b border-border pb-3">
                    <h3 class="font-extrabold text-sm text-text-primary">
                        {{ $editingId ? 'تعديل وحدة القياس' : 'إضافة وحدة قياس جديدة' }}
                    </h3>
                    <button type="button" wire:click="$set('showModal', false)" class="text-text-muted hover:text-text-primary text-base font-bold">&times;</button>
                </div>

                <div class="space-y-3 text-xs">
                    <div>
                        <label class="block font-bold text-text-secondary mb-1">رمز الوحدة (Code بالإنجليزية) *</label>
                        <input type="text" wire:model="code" dir="ltr" placeholder="مثال: carton أو box" class="w-full h-8 px-2.5 rounded-control border border-border text-xs font-mono" />
                        @error('code') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.unit_name_ar') }} *</label>
                        <input type="text" wire:model="name_ar" dir="rtl" class="w-full h-8 px-2.5 rounded-control border border-border text-xs" />
                        @error('name_ar') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.unit_name_en') }}</label>
                        <input type="text" wire:model="name_en" dir="ltr" class="w-full h-8 px-2.5 rounded-control border border-border text-xs" />
                        @error('name_en') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.symbol_ar') }}</label>
                            <input type="text" wire:model="symbol_ar" dir="rtl" placeholder="مثال: كرتونة" class="w-full h-8 px-2.5 rounded-control border border-border text-xs" />
                        </div>
                        <div>
                            <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.symbol_en') }}</label>
                            <input type="text" wire:model="symbol_en" dir="ltr" placeholder="مثال: ctn" class="w-full h-8 px-2.5 rounded-control border border-border text-xs font-mono" />
                        </div>
                    </div>
                    <div class="p-3 rounded-control bg-surface-soft border border-border space-y-2">
                        <label class="flex items-center gap-2 cursor-pointer select-none">
                            <input type="checkbox" wire:model.live="allow_fractions" class="rounded border-border text-primary w-4 h-4" />
                            <span class="font-bold text-text-primary">{{ __('inventory.allow_fractions') }}</span>
                        </label>
                        @if ($allow_fractions)
                            <div>
                                <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.decimal_places') }} (0-6)</label>
                                <input type="number" min="0" max="6" wire:model="decimal_places" dir="ltr" class="w-full h-8 px-2.5 rounded-control border border-border text-xs font-mono" />
                            </div>
                        @endif
                    </div>
                    <div class="pt-1">
                        <label class="flex items-center gap-2 cursor-pointer select-none">
                            <input type="checkbox" wire:model="active" class="rounded border-border text-primary w-4 h-4" />
                            <span class="font-bold text-text-primary">{{ __('inventory.active') }}</span>
                        </label>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <button type="button" wire:click="$set('showModal', false)"
                            class="px-3.5 py-1.5 rounded-control border border-border text-text-secondary hover:bg-surface-soft text-xs font-semibold">
                        إلغاء
                    </button>
                    <button type="button" wire:click="save"
                            class="px-4 py-1.5 rounded-control bg-primary text-white hover:bg-primary-hover text-xs font-bold shadow-button">
                        حفظ
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
