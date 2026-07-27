<?php

namespace Thoule\EasyVerein;

use InvalidArgumentException;

/**
 * Eine easyVerein-Organisation, aus der gelesen wird.
 *
 * **Warum das ein eigener Typ ist und kein Array.** Zwei der vier Thoule-Apps lesen aus
 * mehreren easyVerein-Organisationen, zwei aus einer. Ein Paket, das intern von „dem einen
 * Token" ausgeht, ist für die mehr-instanzigen Apps unbrauchbar – nachrüsten hiesse jede
 * Signatur anfassen. Deshalb ist die Instanz von Anfang an erster Parameter jedes Aufrufs;
 * einstellige Apps bekommen eine einzelne Instanz mit dem Namen `default`.
 *
 * Der Name ist zugleich der Schlüssel im Token-Speicher und darf sich deshalb nicht ändern,
 * ohne dass das gespeicherte Token verwaist.
 */
final readonly class Instanz
{
    public function __construct(
        public string $name,
        public string $basisUrl,
        public string $apiKey,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('Eine easyVerein-Instanz braucht einen Namen.');
        }

        if (! str_starts_with($basisUrl, 'https://')) {
            // Der API-Key geht als Bearer-Token mit. Über http wäre er im Klartext unterwegs.
            throw new InvalidArgumentException(
                "Die Basis-URL der Instanz \"{$name}\" muss mit https:// beginnen."
            );
        }
    }

    /**
     * Host der Basis-URL – die Grenze, innerhalb derer Folge-URLs aus Fremdantworten
     * abgerufen werden dürfen (siehe EasyVereinClient::getAbsolut()).
     */
    public function host(): string
    {
        return (string) parse_url($this->basisUrl, PHP_URL_HOST);
    }

    /**
     * Fingerabdruck des Keys, um Doppelverwendung zu erkennen, ohne den Key selbst
     * irgendwo abzulegen oder zu vergleichen.
     */
    public function keyFingerabdruck(): string
    {
        return hash('sha256', $this->apiKey);
    }
}
