<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Instanzen
    |--------------------------------------------------------------------------
    |
    | Eine easyVerein-Organisation je Eintrag. Apps mit nur einer Organisation
    | konfigurieren genau einen Eintrag mit dem Namen `default`.
    |
    | **Ein Key pro Installation × Organisation.** Nicht pro App und nicht pro Verein:
    | Die Rotation macht das alte Token sofort ungültig, zwei Installationen mit
    | demselben Key zerlegen sich also gegenseitig – auch dev und prod derselben App.
    | Wird derselbe Key zweimal eingetragen, wird der zweite Eintrag übersprungen
    | statt mitrotiert.
    |
    */

    'instanzen' => [
        [
            'name' => 'default',
            'basis_url' => env('EASYVEREIN_BASIS_URL', 'https://easyverein.com/api/stable/'),
            'api_key' => env('EASYVEREIN_API_KEY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Token-Rotation
    |--------------------------------------------------------------------------
    |
    | easyVerein-Keys laufen nach 30 Tagen ab. Der Client erneuert, sobald eine
    | Antwort den Header `tokenRefreshNeeded` setzt; `easyverein:token-refresh` ist
    | das Sicherheitsnetz für den Fall, dass tagelang kein Lauf stattfindet. Deutlich
    | unter 30 bleiben – ein abgelaufener Key lässt sich nur noch von Hand ersetzen.
    |
    */

    'refresh_nach_tagen' => (int) env('EASYVEREIN_REFRESH_NACH_TAGEN', 14),

    'tabelle' => 'easyverein_tokens',

    /*
    |--------------------------------------------------------------------------
    | Listen und Paginierung
    |--------------------------------------------------------------------------
    |
    | `page_size` ist serverseitig **hart auf 5 gedeckelt** – höhere Werte werden
    | stillschweigend ignoriert (am 27.07.2026 mit 50 und 100 gemessen). Der Wert
    | steht hier trotzdem, falls easyVerein das Limit ändert.
    |
    | Die Pause zwischen zwei Seiten hält den Lauf unter dem Rate-Limit. Bei 426
    | Datensätzen sind das 93 Seiten; ein vollständiger Lauf dauert damit Minuten
    | und gehört in einen Command oder Job, nie in einen HTTP-Request.
    |
    */

    'seitengroesse' => (int) env('EASYVEREIN_SEITENGROESSE', 5),

    'pause_sekunden' => (float) env('EASYVEREIN_PAUSE_SEKUNDEN', 1),

    /*
    |--------------------------------------------------------------------------
    | Fehlersenke
    |--------------------------------------------------------------------------
    |
    | Das Paket feuert bei jedem Fehler ein `Events\EasyVereinFehler`. Der
    | mitgelieferte Listener schreibt es ins Log (ohne Token, ohne Personendaten).
    | Wer Fehler selbst behandelt – etwa in einer eigenen Fehlertabelle mit Anzeige
    | im Admin-Panel – schaltet ihn hier ab und hängt einen eigenen Listener an.
    |
    */

    'fehler_loggen' => true,

    /*
    |--------------------------------------------------------------------------
    | OIDC-Login (Socialite)
    |--------------------------------------------------------------------------
    |
    | easyVerein serviert **kein** funktionierendes Discovery-Dokument unter
    | `.well-known/openid-configuration` (404). Die drei Endpunkte sind deshalb fest
    | verdrahtet und live bestätigt.
    |
    | Scopes: `openid` liefert bereits sub, name, username, given_name, family_name,
    | email, groups, chairman_group, org_short und sub_orgs auf oberster Ebene.
    | `profile` ergänzt ein Objekt mit name, family_name, picture, joinDate,
    | updated_at. Am 26.07.2026 mit drei echten Logins gemessen.
    |
    | **Bewusst nicht `myself`:** Dessen Objekt trägt 47 Felder – volle Privat- und
    | Firmenadresse, Geburtsdatum, Telefonnummern, Austritts- und Kündigungsdatum.
    | Keine App speichert davon etwas; übertragen wurde es trotzdem bei jedem Login.
    | Das Profilbild liefert `profile` genauso.
    |
    | Ein Scope muss beim easyVerein-Client freigeschaltet sein, sonst scheitert der
    | Login mit „The requested scope was not registered" – das gilt je Client, also
    | für dev und prod getrennt.
    |
    */

    'oidc' => [
        'client_id' => env('EASYVEREIN_CLIENT_ID'),
        'client_secret' => env('EASYVEREIN_CLIENT_SECRET'),
        'redirect' => env('EASYVEREIN_REDIRECT_URI'),

        'scopes' => array_filter(explode(',', (string) env('EASYVEREIN_SCOPES', 'openid,profile'))),

        'authorize_url' => 'https://easyverein.com/oauth2/authorize/',
        'token_url' => 'https://easyverein.com/oauth2/token/',
        'userinfo_url' => 'https://easyverein.com/oauth2/userinfo/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Gruppen → Rollen
    |--------------------------------------------------------------------------
    |
    | Zuordnung von easyVerein-Gruppen auf App-Rollen für den mitgelieferten
    | spatie-Mapper. Apps mit eigenem Rollen-Model hängen sich stattdessen an das
    | Event `Events\BenutzerAngemeldet`.
    |
    | **Der Mapper synchronisiert nur die hier genannten Rollen.** Eine lokal
    | vergebene Rolle, die in keiner Zuordnung vorkommt, überlebt den Login – ein
    | pauschales syncRoles() würde sie still entziehen.
    |
    */

    'gruppen_rollen' => [
        // 'Vorstand' => 'admin',
    ],

];
