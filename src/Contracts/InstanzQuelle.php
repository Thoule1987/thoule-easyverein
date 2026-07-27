<?php

namespace Thoule\EasyVerein\Contracts;

use Thoule\EasyVerein\Instanz;

/**
 * Woher die Zugangsdaten der Instanzen stammen.
 *
 * **Warum das hinter einem Contract liegt.** Nicht jede App liest aus `.env`: bring-and-buy
 * hält die easyVerein-Zugangsdaten in einer `settings`-Tabelle und ändert sie über die
 * Oberfläche. Ein Paket, das fest `config()` liest, wäre dort nur mit einem Umweg nutzbar.
 * Die mitgelieferte ConfigInstanzQuelle deckt den Normalfall ab.
 */
interface InstanzQuelle
{
    /**
     * Alle **nutzbaren** Instanzen. Unvollständig konfigurierte fehlen hier, statt einen
     * Lauf scheitern zu lassen: Solange die Zugangsdaten des zweiten Vereinsteils fehlen,
     * soll der Lauf für den ersten sauber durchgehen.
     *
     * @return list<Instanz>
     */
    public function alle(): array;

    public function finden(string $name): ?Instanz;

    /**
     * Was `alle()` ausgelassen hat und warum – damit ein Lauf berichten kann, welche
     * Instanz er nicht angefasst hat. Ohne das sieht eine übersprungene Instanz aus wie
     * eine, die nichts zu liefern hatte.
     *
     * @return array<string, string> Instanzname => Grund
     */
    public function uebersprungene(): array;
}
