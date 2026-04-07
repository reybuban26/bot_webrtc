<?php

namespace App\Providers\Filament;

use App\Filament\Resources\ChatMessageResource;
use App\Filament\Resources\ChatSessionResource;
use App\Filament\Widgets\ChatStatsWidget;
use App\Filament\Widgets\RecentMessagesWidget;
use App\Filament\Widgets\CallDurationWidget;
use App\Filament\Widgets\TicketsResolvedWidget;
use App\Filament\Widgets\TokenUsageWidget;
use App\Filament\Widgets\TokenCostWidget;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Leandrocfe\FilamentApexCharts\FilamentApexChartsPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary'   => Color::Violet,
                'secondary' => Color::Indigo,
                'info'      => Color::Blue,
                'success'   => Color::Emerald,
                'warning'   => Color::Amber,
                'danger'    => Color::Rose,
            ])
            ->darkMode(true)
            ->brandName('AI Chatbot Admin')
            ->brandLogo(null)
            ->favicon(null)
            // ── ApexCharts Plugin ──────────────────────────────────────────
            ->plugins([
                FilamentApexChartsPlugin::make(),
            ])
            ->renderHook(
                'panels::head.end',
                fn () => new \Illuminate\Support\HtmlString(
                    '<link rel="stylesheet" href="' . asset('css/admin-theme.css') . '?v=' . filemtime(public_path('css/admin-theme.css')) . '">'
                )
            )
            ->renderHook(
                'panels::auth.login.form.before',
                fn () => new \Illuminate\Support\HtmlString('
                    <div class="custom-login-header">
                        <div class="logo-row">
                            <div class="logo-icon-box">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23-.693L5 14.5m14.8.8l1.402 1.402c1 1 .03 2.7-1.41 2.38l-1.98-.495a9.038 9.038 0 01-4.61 0l-1.98.495c-1.44.32-2.41-1.38-1.41-2.38L5 14.5"/>
                                </svg>
                            </div>
                            <div class="logo-text-block">
                                <span class="logo-app-name">AI Chatbot</span>
                                <span class="logo-app-sub">Admin Panel</span>
                            </div>
                        </div>
                        <h2 class="custom-welcome">Welcome back</h2>
                        <p class="custom-subtitle">Sign in to continue your conversations</p>
                    </div>
                ')
            )
            ->renderHook(
                'panels::auth.login.form.after',
                fn () => new \Illuminate\Support\HtmlString('
                    <div style="text-align: center; margin-top: 1.2rem;">
                        <a href="/forgot-password" style="font-size: 0.875rem; font-weight: 500; color: rgba(var(--primary-500), 1); text-decoration: underline;">
                            Forgot your password?
                        </a>
                    </div>
                ')
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                ChatStatsWidget::class,
                RecentMessagesWidget::class,
                CallDurationWidget::class,
                TicketsResolvedWidget::class,
                TokenUsageWidget::class,
                TokenCostWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}