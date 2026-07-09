<x-layouts::auth :title="__('Log in')">
    <x-auth-header :title="__('Sign In')" :description="__('Enter your email and password below')" />

    <x-auth-session-status class="text-center" :status="session('status')" />

    <x-passkey-verify />

    <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6 mt-4">
        @csrf

        <flux:input
            name="email"
            :label="__('Email address')"
            :value="old('email')"
            type="email"
            required
            autofocus
            autocomplete="email"
            placeholder="email@example.com"
        />

        <div class="relative">
            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="current-password"
                :placeholder="__('Password')"
                viewable
            />

            @if (Route::has('password.request'))
                <flux:link class="absolute top-0 text-sm end-0 text-zinc-400 hover:text-white" :href="route('password.request')" wire:navigate>
                    {{ __('Forgot your password?') }}
                </flux:link>
            @endif
        </div>

        <flux:checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />

        <div class="flex items-center justify-end mt-2">
            <flux:button variant="danger" type="submit" class="w-full text-lg font-bold" data-test="login-button">
                {{ __('Sign In') }}
            </flux:button>
        </div>
    </form>

    <div class="mt-4 text-sm text-center text-zinc-400">
        <span>{{ __('New to our platform?') }}</span>
        <flux:link :href="route('register')" class="text-white hover:underline font-medium" wire:navigate>{{ __('Sign up now') }}</flux:link>
    </div>
</x-layouts::auth>