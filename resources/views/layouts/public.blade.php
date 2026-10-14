<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:header container class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <x-app-logo href="{{ route('home') }}" wire:navigate />

            <flux:navbar class="-mb-px max-sm:hidden">
                <flux:navbar.item icon="ticket" :href="route('home')" :current="request()->routeIs('home', 'events.*')" wire:navigate>
                    イベント一覧
                </flux:navbar.item>
                @auth
                    <flux:navbar.item icon="clipboard-document-list" :href="route('orders.index')" :current="request()->is('orders*')" wire:navigate>
                        マイチケット
                    </flux:navbar.item>
                @endauth
                @can('create', App\Models\Event::class)
                    <flux:navbar.item icon="calendar-days" :href="route('organizer.events.index')" :current="request()->routeIs('organizer.events.*')" wire:navigate>
                        主催者メニュー
                    </flux:navbar.item>
                @endcan
            </flux:navbar>

            <flux:spacer />

            @auth
                <flux:dropdown position="bottom" align="end">
                    <flux:profile :initials="auth()->user()->initials()" icon-trailing="chevron-down" data-test="public-user-menu" />

                    <flux:menu>
                        <div class="px-2 py-1.5 text-sm">
                            <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                            <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                        </div>
                        <flux:menu.separator />
                        <flux:menu.item icon="ticket" :href="route('home')" wire:navigate class="sm:hidden">イベント一覧</flux:menu.item>
                        <flux:menu.item icon="clipboard-document-list" :href="route('orders.index')" wire:navigate class="sm:hidden">マイチケット</flux:menu.item>
                        @can('create', App\Models\Event::class)
                            <flux:menu.item icon="calendar-days" :href="route('organizer.events.index')" wire:navigate class="sm:hidden">主催者メニュー</flux:menu.item>
                        @endcan
                        <flux:menu.item icon="cog" :href="route('profile.edit')" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                        <form method="POST" action="{{ route('logout') }}" class="w-full">
                            @csrf
                            <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer">
                                {{ __('Log out') }}
                            </flux:menu.item>
                        </form>
                    </flux:menu>
                </flux:dropdown>
            @else
                <div class="flex items-center gap-2">
                    <flux:button size="sm" variant="ghost" :href="route('login')" wire:navigate>ログイン</flux:button>
                    @if (Route::has('register'))
                        <flux:button size="sm" variant="primary" :href="route('register')" wire:navigate>会員登録</flux:button>
                    @endif
                </div>
            @endauth
        </flux:header>

        <flux:main container>
            {{ $slot }}
        </flux:main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
