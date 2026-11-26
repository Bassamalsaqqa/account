<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component {};
?>

<div>
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login.store') }}">
        @csrf

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" :aria-invalid="$errors->has('email') ? 'true' : 'false'" :aria-describedby="$errors->has('email') ? 'email-error' : null" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" dir="ltr" />
            <x-input-error id="email-error" :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />
            <x-password-input id="password" :aria-invalid="$errors->has('password') ? 'true' : 'false'" :aria-describedby="$errors->has('password') ? 'password-error' : null" class="block mt-1" name="password" required autocomplete="current-password" />
            <x-input-error id="password-error" :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="block mt-4">
            <label for="remember" class="inline-flex min-h-11 items-center cursor-pointer">
                <x-checkbox id="remember" name="remember" value="1" />
                <span class="ms-2 text-sm text-text-secondary">{{ __('Remember me') }}</span>
            </label>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-4 mt-4">
            @if (Route::has('password.request'))
                <a class="underline text-sm text-text-secondary hover:text-text-primary rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary" href="{{ route('password.request') }}">
                    {{ __('Forgot your password?') }}
                </a>
            @endif

            <x-primary-button class="w-full sm:w-auto max-w-full whitespace-normal">
                {{ __('Log in') }}
            </x-primary-button>
        </div>
    </form>
</div>
