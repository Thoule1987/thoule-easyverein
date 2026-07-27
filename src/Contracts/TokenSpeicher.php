<?php

namespace Thoule\EasyVerein\Contracts;

use Thoule\EasyVerein\Instanz;

/**
 * Wo das jeweils gültige API-Token einer Instanz liegt.
 *
 * **Warum das hinter einem Contract liegt.** easyVerein-Keys laufen nach 30 Tagen ab und
 * werden bei der Rotation **sofort** ungültig – das aktuelle Token muss also irgendwo
 * persistent stehen, und `.env` ist dafür der falsche Ort (auf Shared Hosting nicht
 * zuverlässig schreibbar, und ein Deploy überschreibt sie). Die mitgelieferte
 * DatenbankTokenSpeicher-Implementierung ist der Normalfall; der Contract hält die Tür für
 * einen später gemeinsamen Speicher offen, ohne ihn heute bauen zu müssen.
 */
interface TokenSpeicher
{
    /**
     * Das aktuell gültige Token. Beim ersten Zugriff wird der Key aus der Instanz-
     * Konfiguration übernommen; danach ist der Speicher massgeblich.
     */
    public function aktuellesToken(Instanz $instanz): string;

    /**
     * Persistiert ein rotiertes Token. Muss das alte sofort ersetzen – ein paralleler
     * Aufruf darf nicht mit dem bereits ungültigen Token weiterarbeiten.
     */
    public function speichern(Instanz $instanz, string $neuesToken): void;

    /**
     * Ob proaktiv erneuert werden sollte, weil das Token älter als `$tage` ist oder
     * noch nie erneuert wurde.
     */
    public function brauchtProaktivenRefresh(Instanz $instanz, int $tage): bool;
}
