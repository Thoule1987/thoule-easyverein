<?php

use Illuminate\Support\Facades\Http;
use Thoule\EasyVerein\Contracts\InstanzQuelle;
use Thoule\EasyVerein\Contracts\TokenSpeicher;

it('meldet Erfolg, wenn alle Token frisch sind', function () {
    Http::fake();

    $this->artisan('easyverein:token-refresh')
        ->expectsOutputToContain('hauptverein: Token noch frisch.')
        ->assertSuccessful();
});

it('erneuert ein zu altes Token', function () {
    Http::fake([
        'https://easyverein.com/api/stable/refresh-token' => Http::response(['token' => 'neu']),
    ]);

    // Erster Zugriff legt die Zeile an; danach altern lassen.
    app(TokenSpeicher::class)->aktuellesToken(instanz());
    $this->travel(20)->days();

    $this->artisan('easyverein:token-refresh')
        ->expectsOutputToContain('hauptverein: Token erneuert.')
        ->assertSuccessful();
});

it('scheitert mit Exit-Code 1, wenn eine Instanz nicht erneuert werden kann', function () {
    // Im Scheduler ist der Exit-Code der einzige Weg, wie ein Fehlschlag auffällt.
    Http::fake([
        'https://easyverein.com/api/stable/refresh-token' => Http::response('', 500),
    ]);

    app(TokenSpeicher::class)->aktuellesToken(instanz());
    $this->travel(20)->days();

    $this->artisan('easyverein:token-refresh')->assertFailed();
});

it('nennt uebersprungene Instanzen, statt sie zu verschweigen', function () {
    // Ohne diese Zeile sieht eine übersprungene Instanz aus wie eine, bei der alles in
    // Ordnung war.
    config()->set('easyverein.instanzen', [
        ['name' => 'hauptverein', 'basis_url' => 'https://easyverein.com/api/stable/', 'api_key' => 'k'],
        ['name' => 'abteilung', 'basis_url' => 'https://easyverein.com/api/stable/', 'api_key' => null],
    ]);
    Http::fake();

    $this->artisan('easyverein:token-refresh')
        ->expectsOutputToContain('abteilung: übersprungen (nicht konfiguriert)')
        ->assertSuccessful();
});

it('laesst eine gescheiterte Instanz die anderen nicht mitnehmen', function () {
    config()->set('easyverein.instanzen', [
        ['name' => 'hauptverein', 'basis_url' => 'https://kaputt.example/api/', 'api_key' => 'k1'],
        ['name' => 'abteilung', 'basis_url' => 'https://easyverein.com/api/stable/', 'api_key' => 'k2'],
    ]);

    Http::fake([
        'https://kaputt.example/*' => Http::response('', 500),
        'https://easyverein.com/api/stable/refresh-token' => Http::response(['token' => 'neu']),
    ]);

    $speicher = app(TokenSpeicher::class);
    $quelle = app(InstanzQuelle::class);
    foreach ($quelle->alle() as $i) {
        $speicher->aktuellesToken($i);
    }
    $this->travel(20)->days();

    $this->artisan('easyverein:token-refresh')->assertFailed();

    expect($speicher->aktuellesToken($quelle->finden('abteilung')))->toBe('neu');
});
