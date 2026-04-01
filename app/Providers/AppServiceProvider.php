<?php

namespace App\Providers;

use App\Models\ChatSession;
use App\Policies\ChatSessionPolicy;
use Filament\View\PanelsRenderHook;
use Filament\Support\Facades\FilamentView;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider;
use Illuminate\Support\Facades\Blade;

class AppServiceProvider extends AuthServiceProvider
{
    /**
     * The model to policy mappings.
     */
    protected $policies = [
        ChatSession::class => ChatSessionPolicy::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        if (config('app.env') === 'production') {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        // Cross-tab auto-logout for the Filament admin panel.
        // Direction 1: chat tab logs out → admin tab auto-redirects to /admin/login
        // Direction 2: admin tab logs out → chat tab auto-redirects to /login
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn (): string => Blade::render("
                <script>
                    // Direction 1: detect logout triggered by another tab (e.g. /chat logout)
                    window.addEventListener('storage', function(e) {
                        if (e.key === 'app_logout') {
                            window.location.href = '/admin/login';
                        }
                    });

                    // Direction 2: when admin submits the logout form, notify all other tabs.
                    // Filament uses a regular POST form to /admin/logout (not wire:click).
                    // Capture phase fires before the form actually submits.
                    document.addEventListener('submit', function(e) {
                        if (e.target && e.target.action && e.target.action.includes('admin/logout')) {
                            localStorage.setItem('app_logout', Date.now());
                        }
                    }, true);
                </script>
            "),
        );
    }
}
