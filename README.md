# thoule/easyverein

easyVerein-Anbindung für die Thoule-Laravel-Apps. Ersetzt vier getrennt gewachsene
Implementierungen durch eine.

> **Status: v0.1.0 – extrahiert, getestet, noch in keiner App eingesetzt.** Der Code stammt aus
> `karlsruher-spieletage`, wo er seit dem 27.07.2026 gegen die echte API verifiziert läuft
> (426 Mitglieder, 93 Seiten, drei volle Läufe). 36 Tests gegen `orchestra/testbench`, Pint und
> Larastan Level 5 grün. Die `0.x`-Version ist Absicht: Erst wenn die erste App tatsächlich
> darauf läuft (TASK-130), ist die Schnittstelle erprobt – dann folgt `v1.0.0`.

## Installation

```bash
composer require thoule/easyverein
php artisan vendor:publish --tag=easyverein-config
php artisan vendor:publish --tag=easyverein-migrations   # entfällt, wenn die Tabelle schon existiert
php artisan migrate
```

Die Migration läuft **nicht** automatisch mit: Zwei der Apps haben `easyverein_tokens` bereits
über eine eigene Migration angelegt, und eine mitlaufende Paket-Migration würde dort beim
nächsten `migrate` über eine existierende Tabelle stolpern.

## Verwendung

**Liste über alle Seiten lesen.** `datensaetze()` ist ein Generator und lädt Seite für Seite
nach – bei 426 Mitgliedern sind das 93 Anfragen, also nichts für einen HTTP-Request:

```php
use Thoule\EasyVerein\Contracts\InstanzQuelle;
use Thoule\EasyVerein\EasyVereinClient;

foreach (app(InstanzQuelle::class)->alle() as $instanz) {
    foreach (app(EasyVereinClient::class)->datensaetze($instanz, 'member/') as $mitglied) {
        // $mitglied['membershipNumber'], ['resignationDate'], ['contactDetails'] …
    }
}

// Was ausgelassen wurde und warum – sonst sieht eine übersprungene Instanz aus wie eine
// ohne Daten:
app(InstanzQuelle::class)->uebersprungene();   // ['abteilung' => 'nicht konfiguriert']
```

**Token-Rotation.** Der Client erneuert selbsttätig, sobald eine Antwort den Header
`tokenRefreshNeeded` setzt. Als Sicherheitsnetz gehört zusätzlich in den Scheduler:

```php
Schedule::command('easyverein:token-refresh')->daily();
```

**OIDC-Login.** Socialite-Treiber `easyverein`, mit PKCE:

```php
return Socialite::driver('easyverein')->redirect();
```

**Gruppen → Rollen.** Das Paket wertet `groups` nicht aus – die Zuordnung ist in jeder App
anders. Wer spatie nutzt, hängt den mitgelieferten Mapper an; alle anderen einen eigenen
Listener auf dasselbe Event:

```php
Event::listen(BenutzerAngemeldet::class, SpatieGruppenMapper::class);
```

**Nähte überschreiben.** Kommen die Zugangsdaten nicht aus `.env` (bring-and-buy hält sie in
einer `settings`-Tabelle), im eigenen `AppServiceProvider`:

```php
$this->app->singleton(InstanzQuelle::class, EigeneQuelle::class);
```

## Warum es dieses Paket gibt

Vier Anwendungen haben easyVerein je einzeln gebaut – und **jede hat einen Teil richtig, den die
anderen falsch haben**:

| App | kann | kann nicht |
|---|---|---|
| karlsruher-spieletage | Key-Rotation, Paginierung, beide Vereinsteile | – |
| bring-and-buy | richtige Feldnamen, beide Vereinsteile | fällt bei fehlendem Treffer auf einen beliebigen Datensatz zurück |
| baerenthal | Key-Rotation | nur ein Vereinsteil |
| ludothek | Paginierung, beide Vereinsteile | keine Key-Rotation |

Kein einziger dieser Fehler existiert an allen vier Stellen. Genau deshalb braucht es einen Ort,
an dem „richtig" definiert ist – nicht um Zeilen zu sparen.

Zwei Beispiele, was das kostet:

- **karlsruher-spieletage importierte anderthalb Jahre lang null Mitglieder** und meldete Erfolg.
  Der Code las `data`, die API liefert `results`; `?? []` machte daraus ein leeres Ergebnis.
- **Ludothek rotiert seinen Key nicht.** Nutzt eine andere App denselben Key und rotiert ihn,
  steht Ludotheks Sync – unbemerkt, weil dort „kein Error-Log-Eintrag kein sicheres Zeichen für
  Erfolg" ist.

## Gemessenes API-Verhalten (stable/v2, 27.07.2026)

Mit einem echten Key geprüft, nicht aus der Dokumentation abgeleitet:

| | |
|---|---|
| Antwortform | `results`, `next` (absolute URL), `previous`, `current` – **kein** `count` |
| `page_size` | **hart auf 5 gedeckelt**; `50` und `100` liefern ebenfalls 5 |
| Filterung | `?membershipNumber=…` **wirkt** (genau ein Treffer) |
| Mitgliedsfelder | `id`, `membershipNumber`, `resignationDate`, `contactDetails`, `emailOrUserName` |
| Namen | **nicht im Datensatz** – nur hinter der `contactDetails`-URL (`firstName`/`familyName`) |
| Key-Ablauf | 30 Tage; `GET /refresh-token` rotiert, **altes Token sofort ungültig** |

Belastbare Zahl aus dem Betrieb: 426 Mitglieder = **93 Seiten**. Ein vollständiger Lauf inklusive
Namensauflösung dauert Minuten und gehört deshalb in die Queue, nicht in einen HTTP-Request.

## Die vier Nähte

1. **Instanzen sind first class.** Zwei von drei Apps lesen aus mehreren easyVerein-Organisationen.
   Ein Paket mit einem einzigen Token wäre für sie unbrauchbar.
2. **Die Config-Quelle liegt hinter einem Contract.** Nicht jede App liest aus `.env` –
   bring-and-buy hält die Werte in einer `settings`-Tabelle.
3. **Der Token-Speicher liegt hinter einem Contract.** Damit bleibt ein späterer gemeinsamer
   Speicher möglich, ohne ihn heute bauen zu müssen.
4. **Die Fehlersenke ist ein Event, nichts fest Verdrahtetes.** Eine App loggt, eine andere
   schreibt in eine eigene Fehlertabelle.

## Zwei Regeln, die aus echten Ausfällen stammen

**Ein unbekanntes Schema muss laut werden.** Kein `?? []`, kein stilles Null-Ergebnis. Weicht die
Antwort ab, wirft die Bibliothek – und nennt die tatsächlich gelieferten Feldnamen, damit der
erste Lauf die Diagnose selbst liefert.

**Folge-URLs aus der Antwort sind Fremdangaben.** `next` und `contactDetails` werden gegen den
erwarteten Host geprüft, bevor der Client sie mitsamt `Authorization`-Header abruft.

## Ein Key pro Installation

Nicht pro App und nicht pro Verein: **pro Installation × Organisation**. Rotation macht das alte
Token sofort ungültig – teilen sich zwei Installationen einen Key, zerstört die erste Rotation die
zweite. Das gilt auch zwischen dev und prod derselben App.

## Nicht im Paket

Die Mitgliedschaftsprüfungen der einzelnen Apps. Die sind fachlich verschieden und gehören dorthin,
wo die Fachlichkeit liegt.

## Herkunft

Konzept und Arbeitspakete: [`thoule-shared/gemeinsame-basis`](https://github.com/Thoule1987/thoule-shared/tree/main/gemeinsame-basis)
(TASK-129). Vorbild für das Vorgehen ist `thoule-design-tokens`: erst in einer App zum Laufen
bringen, dann herauslösen. Ein Paket, das aus einer funktionierenden Implementierung entsteht, hat
die Fallstricke schon eingebaut.
