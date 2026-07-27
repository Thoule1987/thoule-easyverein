<?php

namespace Thoule\EasyVerein\Instanzen;

use Thoule\EasyVerein\Contracts\InstanzQuelle;
use Thoule\EasyVerein\Instanz;

/**
 * Der Normalfall: Instanzen aus `config('easyverein.instanzen')`, gespeist aus `.env`.
 *
 * Erwartete Form:
 *
 * ```php
 * 'instanzen' => [
 *     ['name' => 'hauptverein', 'basis_url' => '…', 'api_key' => env('…')],
 *     ['name' => 'abteilung',   'basis_url' => '…', 'api_key' => env('…')],
 * ],
 * ```
 *
 * Einstellige Apps konfigurieren genau einen Eintrag mit dem Namen `default`.
 */
class ConfigInstanzQuelle implements InstanzQuelle
{
    /**
     * @return list<Instanz>
     */
    public function alle(): array
    {
        return array_map(
            fn (array $roh): Instanz => $this->bauen($roh),
            $this->brauchbareEintraege(),
        );
    }

    public function finden(string $name): ?Instanz
    {
        foreach ($this->alle() as $instanz) {
            if ($instanz->name === $name) {
                return $instanz;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public function uebersprungene(): array
    {
        $uebersprungen = [];
        $gesehene = [];

        foreach ($this->rohEintraege() as $roh) {
            $name = (string) ($roh['name'] ?? '');

            if ($name === '') {
                continue;
            }

            if (! $this->vollstaendig($roh)) {
                $uebersprungen[$name] = 'nicht konfiguriert';

                continue;
            }

            $fingerabdruck = hash('sha256', (string) $roh['api_key']);

            if (in_array($fingerabdruck, $gesehene, true)) {
                $uebersprungen[$name] = 'derselbe API-Key wie eine andere Instanz';

                continue;
            }

            $gesehene[] = $fingerabdruck;
        }

        return $uebersprungen;
    }

    /**
     * Vollständig konfiguriert **und** mit einem Key, der nicht schon einer anderen Instanz
     * gehört.
     *
     * **Warum die Doppelverwendung ausgeschlossen wird.** Beide Instanzen würden denselben
     * Key rotieren, und die erste Rotation macht das Token der zweiten sofort ungültig –
     * die scheitert danach dauerhaft mit 401, ohne dass irgendwo steht, warum. Real
     * aufgetreten, als für den zweiten Vereinsteil noch kein eigener Key vorlag. Verglichen
     * wird der Fingerabdruck, nie der Key selbst.
     *
     * @return list<array<string, mixed>>
     */
    private function brauchbareEintraege(): array
    {
        $brauchbar = [];
        $gesehene = [];

        foreach ($this->rohEintraege() as $roh) {
            if (! $this->vollstaendig($roh)) {
                continue;
            }

            $fingerabdruck = hash('sha256', (string) $roh['api_key']);

            if (in_array($fingerabdruck, $gesehene, true)) {
                continue;
            }

            $gesehene[] = $fingerabdruck;
            $brauchbar[] = $roh;
        }

        return $brauchbar;
    }

    /**
     * @param  array<string, mixed>  $roh
     */
    private function vollstaendig(array $roh): bool
    {
        return ! empty($roh['name']) && ! empty($roh['basis_url']) && ! empty($roh['api_key']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rohEintraege(): array
    {
        /** @var list<array<string, mixed>> $eintraege */
        $eintraege = array_values((array) config('easyverein.instanzen', []));

        return $eintraege;
    }

    /**
     * @param  array<string, mixed>  $roh
     */
    private function bauen(array $roh): Instanz
    {
        return new Instanz(
            (string) $roh['name'],
            (string) $roh['basis_url'],
            (string) $roh['api_key'],
        );
    }
}
