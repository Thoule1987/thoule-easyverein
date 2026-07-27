<?php

use Thoule\EasyVerein\Events\BenutzerAngemeldet;
use Thoule\EasyVerein\Rollen\SpatieGruppenMapper;

/**
 * Steht für ein Model mit spatie/laravel-permission, ohne das Paket als harte
 * Abhängigkeit zu ziehen. Der Mapper spricht ohnehin nur diese drei Methoden an.
 */
class RollenAttrappe
{
    /** @param list<string> $rollen */
    public function __construct(public array $rollen = []) {}

    public function hasRole(string $rolle): bool
    {
        return in_array($rolle, $this->rollen, true);
    }

    public function assignRole(string $rolle): void
    {
        $this->rollen[] = $rolle;
    }

    public function removeRole(string $rolle): void
    {
        $this->rollen = array_values(array_diff($this->rollen, [$rolle]));
    }
}

beforeEach(function () {
    config()->set('easyverein.gruppen_rollen', ['Vorstand' => 'admin', 'Kasse' => 'buchhaltung']);
});

it('setzt eine Rolle, wenn die Gruppe vorhanden ist', function () {
    $benutzer = new RollenAttrappe;

    (new SpatieGruppenMapper)->handle(new BenutzerAngemeldet($benutzer, ['Vorstand']));

    expect($benutzer->rollen)->toBe(['admin']);
});

it('entzieht eine verwaltete Rolle, wenn die Gruppe wegfaellt', function () {
    $benutzer = new RollenAttrappe(['admin']);

    (new SpatieGruppenMapper)->handle(new BenutzerAngemeldet($benutzer, []));

    expect($benutzer->rollen)->toBe([]);
});

it('laesst eine lokal vergebene, nicht gemappte Rolle den Login ueberleben', function () {
    // Der eigentliche Grund, warum hier kein syncRoles() steht: Das setzt die Rollenliste
    // auf genau die übergebenen Werte – jede lokal vergebene Rolle wäre beim nächsten
    // Login still weg. Genau das tut eine der Thoule-Apps heute.
    $benutzer = new RollenAttrappe(['kiosk-bediener']);

    (new SpatieGruppenMapper)->handle(new BenutzerAngemeldet($benutzer, ['Vorstand']));

    expect($benutzer->rollen)->toContain('kiosk-bediener')->toContain('admin');
});

it('vergibt eine bereits vorhandene Rolle nicht doppelt', function () {
    $benutzer = new RollenAttrappe(['admin']);

    (new SpatieGruppenMapper)->handle(new BenutzerAngemeldet($benutzer, ['Vorstand']));

    expect($benutzer->rollen)->toBe(['admin']);
});

it('tut nichts ohne Zuordnung', function () {
    config()->set('easyverein.gruppen_rollen', []);
    $benutzer = new RollenAttrappe(['admin']);

    (new SpatieGruppenMapper)->handle(new BenutzerAngemeldet($benutzer, []));

    expect($benutzer->rollen)->toBe(['admin']);
});

it('wirft nicht, wenn das Model kein spatie-Model ist', function () {
    // Ein irrtümlich registrierter Mapper darf keinen Login kosten.
    $benutzer = new stdClass;

    (new SpatieGruppenMapper)->handle(new BenutzerAngemeldet($benutzer, ['Vorstand']));
})->throwsNoExceptions();
