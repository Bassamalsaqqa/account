<x-app-layout>
    <x-settings-shell active-section="profile">
        <div class="space-y-5">
            <div class="grid gap-4 xl:grid-cols-2">
                <section class="rounded-card border border-border bg-surface p-4 sm:p-6 shadow-panel">
                    <livewire:profile.update-profile-information-form />
                </section>

                <section class="rounded-card border border-border bg-surface p-4 sm:p-6 shadow-panel">
                    <livewire:profile.update-password-form />
                </section>

                <section class="rounded-card border border-border bg-surface p-4 sm:p-6 shadow-panel xl:col-span-2">
                    @include('profile.two-factor-authentication')
                </section>

                <section class="rounded-card border border-border bg-surface p-4 sm:p-6 shadow-panel xl:col-span-2">
                    <div class="max-w-xl">
                        <livewire:profile.delete-user-form />
                    </div>
                </section>
            </div>
        </div>
    </x-settings-shell>
</x-app-layout>
