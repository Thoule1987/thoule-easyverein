<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Thoule\EasyVerein\Contracts\TokenSpeicher;
use Thoule\EasyVerein\EasyVereinClient;
use Thoule\EasyVerein\EasyVereinException;
use Thoule\EasyVerein\Events\EasyVereinFehler;
use Thoule\EasyVerein\Events\TokenErneuert;
use Thoule\EasyVerein\Instanz;

// Alle Fakes mit vollständiger URL inklusive Schema. Ein Muster ohne `https://` matcht
// nicht – und ein Ablehnungstest wird dadurch stillschweigend gegenstandslos, weil er
// prüft, dass nichts passiert, und ohne Treffer passiert ohnehin nichts.

it('folgt der Paginierung ueber alle Seiten', function () {
    // Der teuerste Fehler der Vorgeschichte: Bei einem Hard-Limit von 5 pro Seite liest
    // eine App ohne next-Schleife bei 426 Datensätzen genau 1 % – und nichts wird rot.
    Http::fake([
        'https://easyverein.com/api/stable/member/?page=2*' => Http::response(seite([['id' => 3]])),
        'https://easyverein.com/api/stable/member*' => Http::response(seite(
            [['id' => 1], ['id' => 2]],
            'https://easyverein.com/api/stable/member/?page=2&page_size=5',
        )),
    ]);

    $datensaetze = iterator_to_array(
        app(EasyVereinClient::class)->datensaetze(instanz(), 'member/'),
        false,
    );

    expect($datensaetze)->toHaveCount(3)
        ->and(array_column($datensaetze, 'id'))->toBe([1, 2, 3]);
});

it('wirft mit den tatsaechlichen Feldnamen, wenn results fehlt', function () {
    // Genau hier lag der stille Ausfall: `$antwort['data'] ?? []` machte aus einem
    // unbekannten Schema ein leeres Ergebnis und meldete Erfolg.
    Http::fake(['https://easyverein.com/*' => Http::response(['data' => [], 'hinweis' => 'x'])]);

    expect(fn () => iterator_to_array(
        app(EasyVereinClient::class)->datensaetze(instanz(), 'member/')
    ))->toThrow(EasyVereinException::class, 'results');

    try {
        iterator_to_array(app(EasyVereinClient::class)->datensaetze(instanz(), 'member/'));
    } catch (EasyVereinException $e) {
        // Die Meldung muss sagen, WAS kommt – sonst beginnt die Diagnose bei null.
        expect($e->getMessage())->toContain('data')->toContain('hinweis');
    }
});

it('folgt keiner Weiter-URL auf einen fremden Host', function () {
    // `next` ist eine Fremdangabe. Ohne Prüfung liesse sich der Client mitsamt
    // Authorization-Header – also mitsamt API-Key – auf einen beliebigen Server lenken.
    Http::fake([
        'https://easyverein.com/*' => Http::response(seite([['id' => 1]], 'https://angreifer.example/klau')),
        'https://angreifer.example/*' => Http::response(seite([])),
    ]);

    expect(fn () => iterator_to_array(
        app(EasyVereinClient::class)->datensaetze(instanz(), 'member/')
    ))->toThrow(EasyVereinException::class);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'angreifer.example'));
});

it('folgt keiner Weiter-URL ueber http', function () {
    Http::fake([
        'https://easyverein.com/*' => Http::response(seite([['id' => 1]], 'http://easyverein.com/api/stable/member/?page=2')),
        'http://easyverein.com/*' => Http::response(seite([])),
    ]);

    expect(fn () => iterator_to_array(
        app(EasyVereinClient::class)->datensaetze(instanz(), 'member/')
    ))->toThrow(EasyVereinException::class);
});

it('erneuert das Token, wenn die Antwort den Refresh-Header setzt', function () {
    Event::fake([TokenErneuert::class]);

    Http::fake([
        'https://easyverein.com/api/stable/refresh-token' => Http::response(['token' => 'neues-token']),
        'https://easyverein.com/*' => Http::response(seite([['id' => 1]]), 200, ['tokenRefreshNeeded' => 'true']),
    ]);

    app(EasyVereinClient::class)->get(instanz(), 'member/');

    Event::assertDispatched(TokenErneuert::class);

    // Der nächste Aufruf muss das rotierte Token nutzen: Das alte ist ab der Rotation
    // sofort ungültig, ein zwischengespeicherter Wert wäre eine tickende 401.
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer neues-token')
        || $request->hasHeader('Authorization', 'Bearer start-key'));

    expect(app(TokenSpeicher::class)->aktuellesToken(instanz()))
        ->toBe('neues-token');
});

it('laesst einen fehlgeschlagenen Refresh die Datenantwort nicht kaputtmachen', function () {
    // Der Refresh ist Vorsorge. Schlägt er fehl, ist die erfolgreiche Datenantwort
    // trotzdem gültig – einen laufenden Import deswegen abzubrechen wäre falsch.
    Http::fake([
        'https://easyverein.com/api/stable/refresh-token' => Http::response('', 500),
        'https://easyverein.com/*' => Http::response(seite([['id' => 1]]), 200, ['tokenRefreshNeeded' => 'true']),
    ]);

    $antwort = app(EasyVereinClient::class)->get(instanz(), 'member/');

    expect($antwort['results'])->toHaveCount(1);
});

it('meldet einen API-Fehler als Event statt ihn selbst zu loggen', function () {
    Event::fake([EasyVereinFehler::class]);

    Http::fake(['https://easyverein.com/*' => Http::response('', 500)]);

    // Nicht `Throwable::class`: Pest behandelt ein Interface als erwartete Meldung, nicht
    // als erwarteten Typ – der Test wäre dann grün, sobald der Text zufällig passt.
    expect(fn () => app(EasyVereinClient::class)->get(instanz(), 'member/'))
        ->toThrow(RequestException::class);

    Event::assertDispatched(EasyVereinFehler::class, function (EasyVereinFehler $e) {
        // Kein Token, keine Personendaten – nur die Instanz.
        return $e->instanz === 'hauptverein'
            && ! str_contains($e->nachricht, 'start-key');
    });
});

it('erneuert proaktiv nur, wenn das Token zu alt ist', function () {
    Http::fake([
        'https://easyverein.com/api/stable/refresh-token' => Http::response(['token' => 'neues-token']),
    ]);

    $client = app(EasyVereinClient::class);

    // Frisch aus der Konfiguration übernommen: kein Refresh, sonst löste der allererste
    // Scheduler-Lauf sofort eine unnötige Rotation aus.
    expect($client->tokenProaktivErneuern(instanz()))->toBeFalse();

    $this->travel(20)->days();

    expect($client->tokenProaktivErneuern(instanz()))->toBeTrue();
});

it('nimmt auch ein Refresh-Token an, das als nackter JSON-String kommt', function () {
    Http::fake([
        'https://easyverein.com/api/stable/refresh-token' => Http::response('"nacktes-token"'),
    ]);

    // Erst anlegen, dann altern lassen: Die Zeile entsteht beim ersten Zugriff mit
    // `last_refreshed_at = jetzt`. Wer nach dem Zeitsprung zum ersten Mal zugreift, legt
    // sie dort an – und sie ist damit per Definition frisch.
    app(TokenSpeicher::class)->aktuellesToken(instanz());
    $this->travel(20)->days();

    app(EasyVereinClient::class)->tokenProaktivErneuern(instanz());

    expect(app(TokenSpeicher::class)->aktuellesToken(instanz()))
        ->toBe('nacktes-token');
});

it('haelt Instanzen auseinander', function () {
    // Mehr-Instanz-Fähigkeit ist der Grund, warum die Instanz erster Parameter jedes
    // Aufrufs ist: Zwei Organisationen, zwei Token, kein gemeinsamer Zustand.
    Http::fake(['https://easyverein.com/*' => Http::response(seite([]))]);

    $speicher = app(TokenSpeicher::class);
    $eins = new Instanz('eins', 'https://easyverein.com/api/stable/', 'key-eins');
    $zwei = new Instanz('zwei', 'https://easyverein.com/api/stable/', 'key-zwei');

    expect($speicher->aktuellesToken($eins))->toBe('key-eins')
        ->and($speicher->aktuellesToken($zwei))->toBe('key-zwei');

    $speicher->speichern($eins, 'rotiert');

    expect($speicher->aktuellesToken($eins))->toBe('rotiert')
        ->and($speicher->aktuellesToken($zwei))->toBe('key-zwei');
});
