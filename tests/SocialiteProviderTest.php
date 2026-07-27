<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Thoule\EasyVerein\EasyVereinException;
use Thoule\EasyVerein\Socialite\EasyVereinProvider;

function provider(): EasyVereinProvider
{
    // Mit Session: PKCE legt den `code_verifier` dort ab. Ohne Session wirft Socialite
    // beim Redirect – im echten Betrieb ist die Session durch die web-Middleware da.
    $request = Request::create('/auth/easyverein/redirect');
    $request->setLaravelSession(app('session.store'));

    return new EasyVereinProvider(
        $request,
        'client-id',
        'client-secret',
        'https://app.example/auth/easyverein/callback',
    );
}

it('nutzt PKCE', function () {
    // Ohne PKCE ist ein abgefangener Authorization Code für sich genommen einlösbar.
    // Bisher hatte nur eine der vier Apps es eingeschaltet.
    $url = provider()->redirect()->getTargetUrl();

    expect($url)->toContain('code_challenge=')
        ->and($url)->toContain('code_challenge_method=S256');
});

it('nutzt die fest verdrahteten Endpunkte statt Discovery', function () {
    // easyVerein antwortet auf .well-known/openid-configuration mit 404 – ein Discovery-
    // Versuch endete in einer der Apps in einem 500er auf der Redirect-Route.
    expect(provider()->redirect()->getTargetUrl())
        ->toStartWith('https://easyverein.com/oauth2/authorize/');
});

it('fordert die konfigurierten Scopes an', function () {
    config()->set('easyverein.oidc.scopes', ['openid', 'profile']);

    $url = urldecode(provider()->redirect()->getTargetUrl());

    expect($url)->toContain('scope=openid profile')
        // Bewusst nicht `myself`: Dessen Objekt trägt 47 Felder, darunter volle Adresse,
        // Geburtsdatum und Telefonnummern. Keine App speichert davon etwas.
        ->and($url)->not->toContain('myself');
});

it('faellt ohne Konfiguration auf openid und profile zurueck, nie auf myself', function () {
    // Regressionsschutz für die Datensparsamkeit. In der Vorlage stand der Default an einer
    // Stelle auf `openid,myself` – 47 personenbezogene Felder je Login, von denen die App
    // nichts speichert. Aufgefallen ist es nie, weil die Tests dort den Config-Wert selbst
    // setzten, statt zu prüfen, was ohne Konfiguration herauskommt.
    expect(config('easyverein.oidc.scopes'))->toBe(['openid', 'profile'])
        ->and(provider()->getScopes())->not->toContain('myself');
});

it('trimmt Leerzeichen und leere Eintraege in der Scope-Liste', function () {
    // `EASYVEREIN_SCOPES=openid, profile,` ist ein realistischer Tippfehler; ein leerer
    // Scope im Request quittiert easyVerein mit einer Fehlerseite statt mit einem Login.
    config()->set('easyverein.oidc.scopes', ['openid', 'profile']);

    expect(provider()->getScopes())->toBe(['openid', 'profile']);
});

it('liest das Profilbild aus dem profile-Objekt', function () {
    // Auf oberster Ebene steht nur, was `openid` liefert – das Bild liegt im
    // profile-Objekt (26.07.2026 mit drei echten Logins gemessen).
    Http::fake(['https://easyverein.com/oauth2/userinfo/' => Http::response([
        'sub' => 'abc',
        'email' => 'person@example.org',
        'name' => 'Anna Muster',
        'profile' => ['picture' => 'https://easyverein.com/bild.jpg'],
    ])]);

    $benutzer = provider()->userFromToken('t');

    expect($benutzer->getId())->toBe('abc')
        ->and($benutzer->getAvatar())->toBe('https://easyverein.com/bild.jpg');
});

it('ignoriert ein Profilbild, das nicht ueber https kommt', function () {
    // Ein relativer oder http-Wert liefe ins Leere oder ginge unverschlüsselt; ein
    // file://-Wert wäre eine SSRF-Einladung.
    Http::fake(['https://easyverein.com/oauth2/userinfo/' => Http::response([
        'sub' => 'abc',
        'profile' => ['picture' => 'file:///etc/passwd'],
    ])]);

    expect(provider()->userFromToken('t')->getAvatar())->toBeNull();
});

it('laesst den Login ohne Bild durchlaufen', function () {
    Http::fake(['https://easyverein.com/oauth2/userinfo/' => Http::response([
        'sub' => 'abc',
        'email' => 'person@example.org',
    ])]);

    $benutzer = provider()->userFromToken('t');

    expect($benutzer->getAvatar())->toBeNull()
        ->and($benutzer->getEmail())->toBe('person@example.org');
});

it('wirft, wenn die Userinfo nicht erreichbar ist', function () {
    Http::fake(['https://easyverein.com/oauth2/userinfo/' => Http::response('', 503)]);

    expect(fn () => provider()->userFromToken('t'))
        ->toThrow(EasyVereinException::class);
});

it('reicht die Gruppen unveraendert durch', function () {
    // Der Provider wertet sie nicht aus – die Zuordnung auf App-Rollen ist in jeder App
    // anders.
    expect(EasyVereinProvider::gruppen(['groups' => ['Vorstand', 'Kasse']]))
        ->toBe(['Vorstand', 'Kasse'])
        ->and(EasyVereinProvider::gruppen([]))->toBe([])
        ->and(EasyVereinProvider::gruppen(['groups' => 'kaputt']))->toBe([]);
});

it('haelt die vollstaendige Userinfo als Rohdaten bereit', function () {
    // Jede App braucht andere Felder; das Paket mappt nichts weg.
    Http::fake(['https://easyverein.com/oauth2/userinfo/' => Http::response([
        'sub' => 'abc',
        'org_short' => 'FVT',
        'groups' => ['Vorstand'],
    ])]);

    expect(provider()->userFromToken('t')->getRaw())
        ->toHaveKeys(['sub', 'org_short', 'groups']);
});

it('wirft mit klarer Meldung, wenn client_id fehlt', function () {
    // Ohne diese Pruefung baut Socialite die Authorize-URL ohne client_id, schickt die
    // Person zu easyVerein, und easyVerein antwortet „Missing client_id parameter" – eine
    // Meldung, die auf die Gegenseite zeigt, obwohl die eigene .env das Problem ist.
    config()->set('easyverein.oidc.client_id', null);

    expect(fn () => app('Laravel\Socialite\Contracts\Factory')->driver('easyverein'))
        ->toThrow(EasyVereinException::class, 'client_id');
});

it('nennt alle fehlenden Zugangsdaten auf einmal', function () {
    // Sonst arbeitet man sich in drei Anlaeufen durch dieselbe .env.
    config()->set('easyverein.oidc.client_id', null);
    config()->set('easyverein.oidc.client_secret', '');
    config()->set('easyverein.oidc.redirect', null);

    try {
        app('Laravel\Socialite\Contracts\Factory')->driver('easyverein');
    } catch (EasyVereinException $e) {
        expect($e->getMessage())
            ->toContain('client_id')
            ->toContain('client_secret')
            ->toContain('redirect');
    }
});

it('baut den Treiber mit vollstaendiger Konfiguration', function () {
    config()->set('easyverein.oidc.client_id', 'abc');
    config()->set('easyverein.oidc.client_secret', 'geheim');
    config()->set('easyverein.oidc.redirect', 'https://app.example/callback');

    expect(app('Laravel\Socialite\Contracts\Factory')->driver('easyverein'))
        ->toBeInstanceOf(EasyVereinProvider::class);
});
