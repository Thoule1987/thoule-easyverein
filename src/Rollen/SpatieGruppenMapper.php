<?php

namespace Thoule\EasyVerein\Rollen;

use Thoule\EasyVerein\Events\BenutzerAngemeldet;

/**
 * Optionaler Listener: bildet easyVerein-Gruppen auf spatie-Rollen ab.
 *
 * Wird **nicht** automatisch registriert. Apps, die spatie/laravel-permission nutzen,
 * hängen ihn selbst an:
 *
 * ```php
 * Event::listen(BenutzerAngemeldet::class, SpatieGruppenMapper::class);
 * ```
 *
 * Apps mit eigenem Rollen-Model (etwa mit auf eine Veranstaltung skopierten Zuweisungen)
 * schreiben stattdessen einen eigenen Listener auf dasselbe Event.
 *
 * ## Warum hier kein syncRoles() steht
 *
 * `syncRoles()` setzt die Rollenliste auf genau die übergebenen Werte – **jede lokal
 * vergebene Rolle wäre beim nächsten Login still weg**. Genau das tut eine der Thoule-Apps
 * heute. Dieser Mapper fasst deshalb ausschliesslich Rollen an, die in der Zuordnung
 * vorkommen: verwaltete Rollen werden gesetzt oder entzogen, alle anderen bleiben
 * unberührt.
 */
class SpatieGruppenMapper
{
    public function handle(BenutzerAngemeldet $event): void
    {
        $benutzer = $event->benutzer;

        if (! is_object($benutzer) || ! method_exists($benutzer, 'assignRole')) {
            // Ohne spatie auf dem Model ist nichts zu tun. Ein Fehler wäre das nur, wenn
            // jemand den Mapper irrtümlich registriert hat – das soll keinen Login kosten.
            return;
        }

        /** @var array<string, string> $zuordnung */
        $zuordnung = config('easyverein.gruppen_rollen', []);

        if ($zuordnung === []) {
            return;
        }

        foreach ($zuordnung as $gruppe => $rolle) {
            $sollHaben = in_array($gruppe, $event->gruppen, true);
            $hat = $benutzer->hasRole($rolle);

            if ($sollHaben && ! $hat) {
                $benutzer->assignRole($rolle);

                continue;
            }

            // Entzug nur für verwaltete Rollen: Wer die Gruppe verliert, verliert die
            // zugehörige Rolle – aber nur diese.
            if (! $sollHaben && $hat) {
                $benutzer->removeRole($rolle);
            }
        }
    }
}
