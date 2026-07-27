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
                $zugangsdaten = [
                    'client_id' => config('easyverein.oidc.client_id'),
                    'client_secret' => config('easyverein.oidc.client_secret'),
                    'redirect' => config('easyverein.oidc.redirect'),
                ];

                // Fehlende Zugangsdaten hier abfangen, statt sie durchzureichen.
                //
                // **Warum das eine eigene Pruefung wert ist.** Ohne sie baut Socialite die
                // Authorize-URL klaglos ohne `client_id`, schickt die Person zu easyVerein –
                // und easyVerein antwortet mit „invalid_request: Missing client_id
                // parameter". Diese Meldung zeigt auf die Gegenseite, obwohl das Problem in
                // der eigenen `.env` liegt, und kostet garantiert eine Fehlersuche an der
                // falschen Stelle. Real aufgetreten am 27.07.2026 auf einer dev-Umgebung.
                $fehlend = array_keys(array_filter($zugangsdaten, fn ($wert): bool => blank($wert)));

                if ($fehlend !== []) {
                    throw new EasyVereinException(sprintf(
                        'easyVerein-OIDC ist unvollstaendig konfiguriert: %s. Pruefe die .env der jeweiligen Umgebung.',
                        implode(', ', array_map(fn (string $k): string => "easyverein.oidc.{$k} fehlt", $fehlend)),
                    ));
                }

                return $socialite->buildProvider(EasyVereinProvider::class, $zugangsdaten);
            });
        });
    }
}
