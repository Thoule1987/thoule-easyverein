<?php

namespace Thoule\EasyVerein;

use RuntimeException;
use Throwable;

/**
 * Jeder Fehler, den dieses Paket selbst feststellt.
 *
 * Trägt bewusst **keinen** Token und keine Personendaten – die Meldung landet in Logs und
 * Fehlerprotokollen. Der Instanzname genügt zur Einordnung.
 */
class EasyVereinException extends RuntimeException
{
    public function __construct(
        string $nachricht,
        public readonly ?string $instanz = null,
        ?Throwable $vorherige = null,
    ) {
        parent::__construct($nachricht, 0, $vorherige);
    }

    /**
     * Ein unbekanntes Antwortschema. Nennt die **tatsächlich gelieferten** Feldnamen –
     * nur Schlüssel, keine Werte.
     *
     * Diese Meldung ist der Grund, warum es die Klasse gibt: Der Vorgänger in KAST fing ein
     * abweichendes Schema mit `?? []` ab und meldete Erfolg bei null importierten
     * Datensätzen – anderthalb Jahre lang. Weicht die Antwort ab, soll der erste Lauf
     * sagen, **was** kommt.
     *
     * @param  array<string, mixed>  $antwort
     */
    public static function unerwartetesSchema(string $erwartetesFeld, array $antwort, ?string $instanz = null): self
    {
        return new self(sprintf(
            'Antwort ohne "%s"-Feld. Geliefert wurden: %s',
            $erwartetesFeld,
            implode(', ', array_keys($antwort)) ?: '(leere Antwort)',
        ), $instanz);
    }
}
