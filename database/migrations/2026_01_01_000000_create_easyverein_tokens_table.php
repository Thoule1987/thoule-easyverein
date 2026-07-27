<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ein API-Token je easyVerein-Instanz.
 *
 * Ab dem ersten Refresh ist diese Tabelle die Quelle der Wahrheit, nicht mehr `.env`:
 * Die Rotation macht das alte Token sofort ungültig, und eine `.env` überlebt weder
 * einen Deploy noch ein Shared-Hosting-Dateisystem zuverlässig.
 *
 * `instanz` ist eindeutig – zwei Zeilen für dieselbe Organisation würden bedeuten, dass
 * eine davon mit einem toten Token arbeitet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->tabelle(), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('instanz')->unique();
            $table->text('token');
            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->tabelle());
    }

    private function tabelle(): string
    {
        return (string) config('easyverein.tabelle', 'easyverein_tokens');
    }
};
