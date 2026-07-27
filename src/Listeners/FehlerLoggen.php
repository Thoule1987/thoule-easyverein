<?php

namespace Thoule\EasyVerein\Listeners;

use Illuminate\Support\Facades\Log;
use Thoule\EasyVerein\Events\EasyVereinFehler;

/**
 * Standard-Fehlersenke: schreibt ins Log.
 *
 * Registriert der Service Provider, solange `config('easyverein.fehler_loggen')` wahr ist.
 * Wer Fehler anders behandelt, schaltet den Wert ab und hängt einen eigenen Listener an
 * `EasyVereinFehler` – das Event bleibt in beiden Fällen dasselbe.
 *
 * Loggt bewusst **kein** Token, keine URL mit Query und keine Personendaten: Der
 * Instanzname genügt zur Einordnung, alles andere landete sonst dauerhaft in einer Datei,
 * die niemand als personenbezogen führt.
 */
class FehlerLoggen
{
    public function handle(EasyVereinFehler $event): void
    {
        Log::error($event->nachricht, array_filter([
            'instanz' => $event->instanz,
            'ausnahme' => $event->ausnahme?->getMessage(),
        ]));
    }
}
