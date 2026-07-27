<?php

namespace Thoule\EasyVerein\Console;

use Illuminate\Console\Command;
use Thoule\EasyVerein\Contracts\InstanzQuelle;
use Thoule\EasyVerein\EasyVereinClient;
use Throwable;

/**
 * Proaktive Token-Rotation für alle konfigurierten Instanzen.
 *
 * Sicherheitsnetz für den Fall, dass tagelang kein API-Aufruf stattfindet und deshalb der
 * header-getriggerte Refresh nie ausgelöst wird. Ein Key, der die 30 Tage überschreitet,
 * lässt sich nur noch von Hand in der easyVerein-Oberfläche ersetzen.
 *
 * Gehört in den Scheduler, täglich.
 */
class TokenRefreshCommand extends Command
{
    protected $signature = 'easyverein:token-refresh';

    protected $description = 'Erneuert die easyVerein-API-Keys proaktiv, wenn sie zu alt sind';

    public function handle(EasyVereinClient $client, InstanzQuelle $quelle): int
    {
        $fehler = false;

        foreach ($quelle->uebersprungene() as $name => $grund) {
            $this->warn("{$name}: übersprungen ({$grund})");
        }

        foreach ($quelle->alle() as $instanz) {
            try {
                $erneuert = $client->tokenProaktivErneuern($instanz);

                $this->line($erneuert
                    ? "{$instanz->name}: Token erneuert."
                    : "{$instanz->name}: Token noch frisch.");
            } catch (Throwable $e) {
                // Eine gescheiterte Instanz darf die anderen nicht mitnehmen – sonst hängt
                // die Rotation aller Instanzen an der schwächsten.
                $this->error("{$instanz->name}: {$e->getMessage()}");
                $fehler = true;
            }
        }

        // Exit-Code 1 bei jedem Fehlschlag: Im Scheduler ist das der einzige Weg, wie ein
        // Problem überhaupt auffällt.
        return $fehler ? self::FAILURE : self::SUCCESS;
    }
}
