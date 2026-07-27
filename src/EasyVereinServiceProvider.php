<?php

namespace Thoule\EasyVerein;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\SocialiteManager;
use Thoule\EasyVerein\Console\TokenRefreshCommand;
use Thoule\EasyVerein\Contracts\InstanzQuelle;
use Thoule\EasyVerein\Contracts\TokenSpeicher;
use Thoule\EasyVerein\Events\EasyVereinFehler;
use Thoule\EasyVerein\Instanzen\ConfigInstanzQuelle;
use Thoule\EasyVerein\Listeners\FehlerLoggen;
use Thoule\EasyVerein\Socialite\EasyVereinProvider;
use Thoule\EasyVerein\Token\DatenbankTokenSpeicher;

class EasyVereinServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/easyverein.php', 'easyverein');

        // Beide Nähte als Default-Bindung: Wer eine andere Quelle braucht – etwa
        // Zugangsdaten aus einer settings-Tabelle statt aus .env – überschreibt sie im
        // eigenen AppServiceProvider.
        $this->app->singleton(InstanzQuelle::class, ConfigInstanzQuelle::class);
        $this->app->singleton(TokenSpeicher::class, DatenbankTokenSpeicher::class);

        $this->app->singleton(EasyVereinClient::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([TokenRefreshCommand::class]);

            $this->publishes([
                __DIR__.'/../config/easyverein.php' => config_path('easyverein.php'),
            ], 'easyverein-config');

            // Bewusst nicht per loadMigrationsFrom() automatisch: Zwei der Apps haben die
            // Tabelle bereits über eine eigene Migration angelegt. Eine mitlaufende
            // Paket-Migration würde dort beim nächsten `migrate` über eine existierende
            // Tabelle stolpern.
            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'easyverein-migrations');
        }

        if (config('easyverein.fehler_loggen', true)) {
            Event::listen(EasyVereinFehler::class, FehlerLoggen::class);
        }

        $this->socialiteTreiberRegistrieren();
    }

    /**
     * Registriert den `easyverein`-Treiber, sofern Socialite installiert ist.
     *
     * Socialite ist keine harte Abhängigkeit: Wer das Paket nur für den Mitglieder-Import
     * nutzt, braucht keinen OIDC-Login.
     */
    private function socialiteTreiberRegistrieren(): void
    {
        if (! interface_exists(SocialiteFactory::class)) {
            return;
        }

        $this->app->afterResolving(SocialiteFactory::class, function (SocialiteFactory $socialite): void {
            /** @phpstan-var SocialiteManager $socialite */
            $socialite->extend('easyverein', function () use ($socialite) {
                return $socialite->buildProvider(EasyVereinProvider::class, [
                    'client_id' => config('easyverein.oidc.client_id'),
                    'client_secret' => config('easyverein.oidc.client_secret'),
                    'redirect' => config('easyverein.oidc.redirect'),
                ]);
            });
        });
    }
}
