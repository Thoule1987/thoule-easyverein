<?php

namespace Thoule\EasyVerein\Events;

use Throwable;

/**
 * Die Fehlersenke des Pakets – eine der vier Nähte.
 *
 * **Warum ein Event und kein fest verdrahtetes `Log::error()`.** Die Apps behandeln Fehler
 * unterschiedlich: eine loggt, eine schreibt in eine eigene Fehlertabelle mit Anzeige im
 * Admin-Panel. Ein Paket, das direkt loggt, zwingt die zweite dazu, das Log zu parsen.
 *
 * Das Paket bringt einen Listener mit, der nach Laravel-Art loggt; er lässt sich über
 * `config('easyverein.fehler_loggen')` abschalten, wenn die App die Behandlung selbst
 * übernimmt.
 *
 * Trägt **keine** Personendaten und **kein** Token.
 */
final readonly class EasyVereinFehler
{
    public function __construct(
        public string $nachricht,
        public ?string $instanz = null,
        public ?Throwable $ausnahme = null,
    ) {}
}
