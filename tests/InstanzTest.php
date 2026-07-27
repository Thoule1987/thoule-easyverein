<?php

use Thoule\EasyVerein\Contracts\InstanzQuelle;
use Thoule\EasyVerein\Instanz;

it('lehnt eine Basis-URL ohne https ab', function () {
    // Der API-Key geht als Bearer-Token mit. Über http wäre er im Klartext unterwegs.
    expect(fn () => new Instanz('x', 'http://easyverein.com/api/stable/', 'k'))
        ->toThrow(InvalidArgumentException::class, 'https');
});

it('leitet den erlaubten Host aus der Basis-URL ab', function () {
    // Nicht fest auf easyverein.com verdrahtet: Der Host der Instanz ist die Grenze,
    // innerhalb derer Folge-URLs abgerufen werden dürfen.
    expect((new Instanz('x', 'https://verein.example/api/', 'k'))->host())
        ->toBe('verein.example');
});

it('uebersprint eine Instanz ohne Zugangsdaten, statt den Lauf scheitern zu lassen', function () {
    config()->set('easyverein.instanzen', [
        ['name' => 'eins', 'basis_url' => 'https://easyverein.com/api/stable/', 'api_key' => 'key-1'],
        ['name' => 'zwei', 'basis_url' => 'https://easyverein.com/api/stable/', 'api_key' => null],
    ]);

    $quelle = app(InstanzQuelle::class);

    expect($quelle->alle())->toHaveCount(1)
        ->and($quelle->alle()[0]->name)->toBe('eins')
        ->and($quelle->uebersprungene())->toBe(['zwei' => 'nicht konfiguriert']);
});

it('uebersprint eine zweite Instanz mit demselben Key', function () {
    // Beide würden denselben Key rotieren – die erste Rotation macht das Token der
    // zweiten sofort ungültig, und die scheitert danach dauerhaft mit 401, ohne dass
    // irgendwo steht warum. Real aufgetreten, als für den zweiten Vereinsteil noch kein
    // eigener Key vorlag.
    config()->set('easyverein.instanzen', [
        ['name' => 'eins', 'basis_url' => 'https://easyverein.com/api/stable/', 'api_key' => 'gleicher'],
        ['name' => 'zwei', 'basis_url' => 'https://easyverein.com/api/stable/', 'api_key' => 'gleicher'],
    ]);

    $quelle = app(InstanzQuelle::class);

    expect($quelle->alle())->toHaveCount(1)
        ->and($quelle->uebersprungene())->toBe(['zwei' => 'derselbe API-Key wie eine andere Instanz']);
});

it('vergleicht Keys ueber den Fingerabdruck, nicht im Klartext', function () {
    $instanz = new Instanz('x', 'https://easyverein.com/api/stable/', 'geheim');

    expect($instanz->keyFingerabdruck())
        ->toBe(hash('sha256', 'geheim'))
        ->not->toContain('geheim');
});

it('findet eine Instanz ueber ihren Namen', function () {
    expect(app(InstanzQuelle::class)->finden('hauptverein'))->not->toBeNull()
        ->and(app(InstanzQuelle::class)->finden('gibtsnicht'))->toBeNull();
});
