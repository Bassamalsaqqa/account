<x-app-layout>
    <div class="space-y-5">
        <header>
            <h1 class="text-2xl font-extrabold text-text-primary">{{ __('app.profile') }}</h1>
        </header>

        <div class="grid gap-4 xl:grid-cols-2">
            <section class="rounded-card border border-border bg-white p-4 sm:p-6 shadow-panel">
                <livewire:profile.update-profile-information-form />
            </section>

            <section class="rounded-card border border-border bg-white p-4 sm:p-6 shadow-panel">
                <livewire:profile.update-password-form />
            </section>

            <section class="rounded-card border border-border bg-white p-4 sm:p-6 shadow-panel xl:col-span-2">
                <div class="max-w-xl">
                    <livewire:profile.delete-user-form />
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
