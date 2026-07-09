<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen antialiased text-white relative bg-black">
        
        <div class="absolute inset-0 z-0 bg-cover bg-center bg-no-repeat" style="background-image: url('{{ asset('authbg.png') }}');">
            <div class="absolute inset-0 bg-black/60 md:bg-black/50 bg-gradient-to-b from-black/80 via-transparent to-black/90"></div>
        </div>

        <div class="relative z-10 flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-md flex-col gap-4">
                
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-2 font-medium" wire:navigate>
                    <span class="flex h-12 w-auto items-center justify-center rounded-md">
                        {{-- Tip: If your logo is an SVG, making it text-red-600 adds to the Netflix vibe --}}
                        <x-app-logo-icon class="h-10 w-auto fill-current text-red-600" />
                    </span>
                    <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
                </a>

                <div class="bg-black/75 rounded-xl p-8 md:p-10 backdrop-blur-sm border border-white/10 shadow-2xl flex flex-col gap-6">
                    {{ $slot }}
                </div>

            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>