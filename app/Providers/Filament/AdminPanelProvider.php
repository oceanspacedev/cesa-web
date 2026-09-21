<?php

namespace App\Providers\Filament;

use App\Filament\Admin\Auth\Login;
use App\Http\Middleware\SetLocale;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Webkul\Support\Filament\Pages\Profile;
use Webkul\Support\GlobalSearchProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        set_time_limit(300);

        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login(Login::class)
            ->favicon(asset('images/favicon.ico'))
            ->brandLogo(asset('images/logo.png'))
            ->brandLogoHeight('2rem')
            ->passwordReset()
            ->emailVerification()
            ->profile()
            ->colors([
                'primary' => Color::Blue,
            ])
            ->unsavedChangesAlerts()
            ->topNavigation()
            ->maxContentWidth(Width::Full)
            ->databaseNotifications()
            ->userMenuItems([
                'profile' => Action::make('profile')
                    ->label(fn () => Auth::user()?->name)
                    ->icon('heroicon-o-user-circle')
                    ->url(fn (): string => Profile::getUrl()),
            ])
            ->navigationGroups([
                NavigationGroup::make()
                    ->label(__('admin.navigation.dashboard'))
                    ->icon('icon-dashboard'),
                NavigationGroup::make()
                    ->label(__('admin.navigation.contact'))
                    ->icon('icon-contacts'),
                NavigationGroup::make()
                    ->label(__('admin.navigation.sale'))
                    ->icon('icon-sales'),
                NavigationGroup::make()
                    ->label(__('admin.navigation.purchase'))
                    ->icon('icon-purchases'),
                NavigationGroup::make()
                    ->label(__('admin.navigation.invoice'))
                    ->icon('icon-invoices'),
                NavigationGroup::make()
                    ->label(__('admin.navigation.accounting'))
                    ->icon('icon-accounting'),
                NavigationGroup::make()
                    ->label(__('admin.navigation.inventory'))
                    ->icon('icon-inventories'),
                NavigationGroup::make()
                    ->label(__('admin.navigation.project'))
                    ->icon('icon-projects'),
                NavigationGroup::make()
                    ->label(__('admin.navigation.employee'))
                    ->icon('icon-employees'),
                NavigationGroup::make()
                    ->label(__('admin.navigation.time-off'))
                    ->icon('icon-time-offs'),
                NavigationGroup::make()
                    ->label(__('admin.navigation.recruitment'))
                    ->icon('icon-recruitments'),
                NavigationGroup::make()
                    ->label(__('admin.navigation.website'))
                    ->icon('icon-website'),
                NavigationGroup::make()
                    ->label(__('admin.navigation.exit-clearance'))
                    ->icon('icon-exit-clearance'),
                NavigationGroup::make()
                    ->label(__('admin.navigation.form-transfer'))
                    ->icon('icon-form-transfer'),
                NavigationGroup::make()
                    ->label(__('admin.navigation.helpdesk'))
                    ->icon('icon-helpdesk'),
                NavigationGroup::make()
                    ->label(__('admin.navigation.id-card'))
                    ->icon('icon-id-card'),
                NavigationGroup::make()
                    ->label(__('admin.navigation.kepegawaian'))
                    ->icon('icon-kepegawaian'),
                NavigationGroup::make()
                    ->label(__('admin.navigation.payroll'))
                    ->icon('icon-payroll'),
                NavigationGroup::make()
                    ->label(__('admin.navigation.presensi'))
                    ->icon('icon-presensi'),
                NavigationGroup::make()
                    ->label(__('admin.navigation.rekrutmen'))
                    ->icon('icon-rekrutmen'),
                NavigationGroup::make()
                    ->label(__('admin.navigation.padelnis'))
                    ->icon('icon-padelnis'),
                NavigationGroup::make()
                    ->label(__('admin.navigation.shelf'))
                    ->icon('icon-shelf'),
                NavigationGroup::make()
                    ->label(__('admin.navigation.lead'))
                    ->icon('icon-lead'),
                NavigationGroup::make()
                    ->label(__('admin.navigation.document'))
                    ->icon('icon-document'),
                NavigationGroup::make()
                    ->label(__('admin.navigation.plugin'))
                    ->icon('icon-plugin'),
                NavigationGroup::make()
                    ->label(__('admin.navigation.setting'))
                    ->icon('icon-settings'),
            ])
            ->plugins([
                FilamentShieldPlugin::make()
                    ->gridColumns([
                        'default' => 1,
                        'sm'      => 1,
                        'lg'      => 2,
                        'xl'      => 3,
                    ])
                    ->sectionColumnSpan(1)
                    ->checkboxListColumns([
                        'default' => 1,
                        'sm'      => 1,
                        'lg'      => 2,
                        'xl'      => 3,
                    ])
                    ->resourceCheckboxListColumns([
                        'default' => 1,
                        'sm'      => 2,
                    ]),
            ])
            ->globalSearch(provider: GlobalSearchProvider::class)
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
                SetLocale::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->multiFactorAuthentication([
                AppAuthentication::make()
                    ->recoverable(),
            ]);
    }
}
