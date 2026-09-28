<x-app-layout>
    <div class="space-y-8">
        <div>
            <h1 class="text-2xl font-extrabold text-text-primary">UI Specimen & Design Tokens</h1>
            <p class="text-xs text-text-muted mt-1">Design system tokens, typography, surfaces, badges, and controls (Development environment only).</p>
        </div>

        <!-- Typography & Numbers -->
        <section class="bg-white border border-border rounded-card p-5 shadow-panel space-y-4">
            <h2 class="text-sm font-extrabold text-text-primary border-b border-border pb-2">1. Typography & Tabular Numerals</h2>
            <div class="space-y-2">
                <div class="text-2xl font-extrabold text-text-primary">Heading 1: نظام المحاسبة للتاجر الصغير (24px Bold)</div>
                <div class="text-lg font-bold text-text-primary">Heading 2: تقارير الأرباح والمبيعات والمخزون (18px Bold)</div>
                <div class="text-sm font-semibold text-text-secondary">Body: خط النظام الأساسي مع وضوح عالي للنصوص العربية والإنجليزية (14px Semibold)</div>
                <div class="text-xs text-text-muted">Small / Meta: تاريخ التعديل، أرقام السجلات والملاحظات (12px Muted)</div>
                <div class="flex items-center gap-4 pt-2">
                    <span class="text-xs text-text-secondary">Tabular Numerals:</span>
                    <span class="num text-base font-bold text-primary">₪ 12,450.50</span>
                    <span class="num text-base font-bold text-success">$ 3,420.00</span>
                    <span class="num text-base font-bold text-text-primary">059-765-4321</span>
                    <span class="num text-xs bg-slate-100 px-2 py-1 rounded font-mono">SKU-FOOD-9082</span>
                </div>
            </div>
        </section>

        <!-- Color Tokens & Badges -->
        <section class="bg-white border border-border rounded-card p-5 shadow-panel space-y-4">
            <h2 class="text-sm font-extrabold text-text-primary border-b border-border pb-2">2. Semantic Badges & Statuses</h2>
            <div class="flex flex-wrap items-center gap-3">
                <x-badge variant="default">Default: 5</x-badge>
                <x-badge variant="info">Primary / Info</x-badge>
                <x-badge variant="success">Active / Complete</x-badge>
                <x-badge variant="warning">Warning / Locked</x-badge>
                <x-badge variant="danger">Alert / Expiry (3)</x-badge>
            </div>
        </section>

        <!-- Buttons & Controls -->
        <section class="bg-white border border-border rounded-card p-5 shadow-panel space-y-4">
            <h2 class="text-sm font-extrabold text-text-primary border-b border-border pb-2">3. Interactive Controls (40-44px Touch Targets)</h2>
            <div class="flex flex-wrap items-center gap-3">
                <button type="button" class="h-10 px-4 rounded-control bg-primary hover:bg-primary-hover text-white text-xs font-bold shadow-sm transition-colors">
                    Primary Button
                </button>
                <button type="button" class="h-10 px-4 rounded-control bg-primary-50 hover:bg-primary-100 text-primary text-xs font-bold transition-colors">
                    Secondary Button
                </button>
                <button type="button" class="h-10 px-4 rounded-control border border-border hover:bg-surface-soft text-text-secondary text-xs font-bold transition-colors">
                    Outline Button
                </button>
                <button type="button" class="h-10 px-4 rounded-control bg-danger hover:bg-red-700 text-white text-xs font-bold transition-colors">
                    Destructive Button
                </button>
            </div>
        </section>
    </div>
</x-app-layout>
