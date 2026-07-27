<?php

namespace Thoule\EasyVerein\Token;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Thoule\EasyVerein\Contracts\TokenSpeicher;
use Thoule\EasyVerein\EasyVereinException;
use Thoule\EasyVerein\Instanz;

/**
 * Token-Speicher in der App-Datenbank, eine Zeile je Instanz.
 *
 * **Warum die Datenbank und nicht `.env`.** Der Key rotiert alle 30 Tage und das alte Token
 * wird sofort ungültig. Eine `.env` ist auf Shared Hosting nicht zuverlässig schreibbar und
 * wird vom nächsten Deploy überschrieben – das rotierte Token wäre weg und die Instanz
 * dauerhaft auf 401. Der Wert aus der Konfiguration ist deshalb nur der **Startwert**: Beim
 * ersten Zugriff wandert er in die Datenbank, ab da ist die Datenbank massgeblich.
 */
class DatenbankTokenSpeicher implements TokenSpeicher
{
    public function aktuellesToken(Instanz $instanz): string
    {
        return $this->eintrag($instanz)->token;
    }

    public function speichern(Instanz $instanz, string $neuesToken): void
    {
        // Unter Row-Lock, damit ein paralleler Lauf nicht mit dem bereits ungültigen Token
        // weiterarbeitet – nach der Rotation gibt es keine Gnadenfrist.
        DB::transaction(function () use ($instanz, $neuesToken): void {
            $eintrag = EasyVereinToken::query()
                ->where('instanz', $instanz->name)
                ->lockForUpdate()
                ->first()
                ?? new EasyVereinToken(['instanz' => $instanz->name]);

            $eintrag->token = $neuesToken;
            $eintrag->last_refreshed_at = Carbon::now();
            $eintrag->save();
        });
    }

    public function brauchtProaktivenRefresh(Instanz $instanz, int $tage): bool
    {
        $zuletzt = $this->eintrag($instanz)->last_refreshed_at;

        // Carbon::parse statt direktem Methodenaufruf: Statische Analyse liest den Typ von
        // der timestamp-Spalte der Migration, nicht von casts() – der Aufruf gilt sonst als
        // Methode auf einem String. Unterdrücken wäre die schlechtere Lösung.
        return $zuletzt === null
            || Carbon::parse($zuletzt)->lt(Carbon::now()->subDays($tage));
    }

    private function eintrag(Instanz $instanz): EasyVereinToken
    {
        $eintrag = EasyVereinToken::query()->where('instanz', $instanz->name)->first();

        if ($eintrag !== null) {
            return $eintrag;
        }

        if ($instanz->apiKey === '') {
            throw new EasyVereinException(
                "Kein easyVerein-API-Key für Instanz \"{$instanz->name}\" konfiguriert.",
                $instanz->name,
            );
        }

        // last_refreshed_at = jetzt statt null: Der Startwert aus der Konfiguration gilt als
        // frisch. Sonst löste der allererste Scheduler-Lauf sofort eine unnötige Rotation
        // aus – und damit einen Key-Wechsel, von dem niemand etwas ahnt.
        return EasyVereinToken::query()->create([
            'instanz' => $instanz->name,
            'token' => $instanz->apiKey,
            'last_refreshed_at' => Carbon::now(),
        ]);
    }
}
