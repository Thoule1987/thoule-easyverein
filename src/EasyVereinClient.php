<?php

namespace Thoule\EasyVerein;

use Generator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Thoule\EasyVerein\Contracts\TokenSpeicher;
use Thoule\EasyVerein\Events\EasyVereinFehler;
use Thoule\EasyVerein\Events\TokenErneuert;
use Throwable;

/**
 * HTTP-Client für die easyVerein-REST-API (`https://easyverein.com/api/stable/`).
 *
 * ## Gemessenes Verhalten (27.07.2026, mit echtem Key)
 *
 * | | |
 * |---|---|
 * | Antwortform | `results`, `next` (absolute URL), `previous`, `current` – **kein** `count` |
 * | `page_size` | **hart auf 5 gedeckelt**; `50` und `100` liefern ebenfalls 5 |
 * | Filterung | `?membershipNumber=…` wirkt (genau ein Treffer) |
 * | Key-Ablauf | 30 Tage; `GET /refresh-token` rotiert, **altes Token sofort ungültig** |
 *
 * Belastbare Zahl aus dem Betrieb: 426 Mitglieder = **93 Seiten**.
 *
 * ## Warum die Paginierung hier liegt und nicht in der App
 *
 * Bei einem Hard-Limit von 5 pro Seite liest eine App ohne `next`-Schleife bei 426
 * Datensätzen genau 1 % – und **nichts wird rot dabei**. Genau dieser Fehler existierte in
 * zwei der vier Thoule-Apps. Er gehört an genau eine Stelle, mit Tests.
 *
 * Ein vollständiger Lauf dauert Minuten. Aufrufer nutzen deshalb `datensaetze()` (ein
 * Generator, der Seite für Seite nachlädt) und rufen das aus einem Command oder Job auf,
 * nie aus einem HTTP-Request.
 */
class EasyVereinClient
{
    public function __construct(private readonly TokenSpeicher $speicher) {}

    /**
     * Eine einzelne Seite relativ zur Basis-URL der Instanz.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(Instanz $instanz, string $pfad, array $query = []): array
    {
        $token = $this->speicher->aktuellesToken($instanz);

        $antwort = $this->abrufen(
            $instanz,
            fn (): Response => Http::withToken($token)
                ->acceptJson()
                ->baseUrl(rtrim($instanz->basisUrl, '/'))
                ->get(ltrim($pfad, '/'), $query)
                ->throw(),
            $token,
        );

        return (array) $antwort->json();
    }

    /**
     * Wie get(), aber gegen eine **absolute** URL aus einer Fremdantwort.
     *
     * Die Paginierung liefert in `next` eine vollständige URL, und Unterressourcen wie
     * `contactDetails` sind ebenfalls absolut. Beide durch `baseUrl()` zu schieben würde
     * sie zerlegen oder verdoppeln.
     *
     * **Die URL stammt von der Gegenseite und wird deshalb geprüft.** Ohne diese Schranke
     * liesse sich der Client über ein manipuliertes `next` auf einen beliebigen Host
     * lenken – mitsamt `Authorization`-Header, also mitsamt dem API-Key. Erlaubt ist
     * ausschliesslich der Host der konfigurierten Basis-URL.
     *
     * @return array<string, mixed>
     */
    public function getAbsolut(Instanz $instanz, string $url): array
    {
        if (! $this->urlErlaubt($instanz, $url)) {
            throw new EasyVereinException(
                'Unerwartete Folge-URL aus der easyVerein-Antwort.',
                $instanz->name,
            );
        }

        $token = $this->speicher->aktuellesToken($instanz);

        $antwort = $this->abrufen(
            $instanz,
            fn (): Response => Http::withToken($token)->acceptJson()->get($url)->throw(),
            $token,
        );

        return (array) $antwort->json();
    }

    /**
     * Alle Seiten einer Liste, der `next`-Kette folgend.
     *
     * @param  array<string, mixed>  $query
     * @return Generator<int, array<string, mixed>>
     */
    public function seiten(Instanz $instanz, string $pfad, array $query = []): Generator
    {
        $pause = (float) config('easyverein.pause_sekunden', 1);
        $query['page_size'] ??= (int) config('easyverein.seitengroesse', 5);

        $naechste = null;

        do {
            $antwort = $naechste === null
                ? $this->get($instanz, $pfad, $query)
                : $this->getAbsolut($instanz, $naechste);

            if (! isset($antwort['results'])) {
                // Ein unbekanntes Schema muss laut werden. `?? []` an dieser Stelle war der
                // Fehler, der in KAST anderthalb Jahre lang Erfolg bei null Datensätzen
                // gemeldet hat.
                throw EasyVereinException::unerwartetesSchema('results', $antwort, $instanz->name);
            }

            yield $antwort;

            $naechste = is_string($antwort['next'] ?? null) && $antwort['next'] !== ''
                ? $antwort['next']
                : null;

            if ($naechste !== null) {
                // Gegen das Rate-Limit. Sleep::fake() macht das in Tests kostenlos.
                Sleep::for($pause)->seconds();
            }
        } while ($naechste !== null);
    }

    /**
     * Alle Datensätze einer Liste über alle Seiten hinweg.
     *
     * @param  array<string, mixed>  $query
     * @return Generator<int, array<string, mixed>>
     */
    public function datensaetze(Instanz $instanz, string $pfad, array $query = []): Generator
    {
        foreach ($this->seiten($instanz, $pfad, $query) as $seite) {
            foreach ($seite['results'] as $datensatz) {
                yield $datensatz;
            }
        }
    }

    /**
     * Proaktiver Refresh (Command/Cron), wenn das Token zu alt ist. Sicherheitsnetz für
     * den Fall, dass tagelang kein Lauf den header-getriggerten Refresh auslöst.
     */
    public function tokenProaktivErneuern(Instanz $instanz): bool
    {
        $tage = (int) config('easyverein.refresh_nach_tagen', 14);

        if (! $this->speicher->brauchtProaktivenRefresh($instanz, $tage)) {
            return false;
        }

        $this->tokenErneuern($instanz, $this->speicher->aktuellesToken($instanz));

        return true;
    }

    /**
     * Gemeinsamer Ablauf beider GET-Varianten: abrufen, Fehler melden, auf den
     * Refresh-Header reagieren.
     *
     * @param  callable(): Response  $aufruf
     */
    private function abrufen(Instanz $instanz, callable $aufruf, string $token): Response
    {
        try {
            $antwort = $aufruf();
        } catch (Throwable $e) {
            $this->fehler('easyVerein-API-Fehler', $instanz, $e);

            throw $e;
        }

        // Der header-getriggerte Refresh ist proaktiv: Schlägt er fehl, bleibt die
        // erfolgreiche Datenantwort gültig, und der Fehler ist bereits gemeldet. Ihn
        // durchschlagen zu lassen würde einen laufenden Import wegen einer Nebensache
        // abbrechen.
        if ($this->refreshSignalisiert($antwort)) {
            try {
                $this->tokenErneuern($instanz, $token);
            } catch (Throwable) {
                // bewusst geschluckt – Datenantwort ist valide, Fehler ist gemeldet
            }
        }

        return $antwort;
    }

    private function refreshSignalisiert(Response $antwort): bool
    {
        $wert = $antwort->header('tokenRefreshNeeded');

        return $wert !== '' && $wert !== '0' && strtolower($wert) !== 'false';
    }

    private function tokenErneuern(Instanz $instanz, string $aktuellesToken): void
    {
        try {
            $antwort = Http::withToken($aktuellesToken)
                ->acceptJson()
                ->baseUrl(rtrim($instanz->basisUrl, '/'))
                ->get('refresh-token');

            if ($antwort->failed()) {
                throw new EasyVereinException(
                    "easyVerein-Token-Refresh fehlgeschlagen ({$antwort->status()}).",
                    $instanz->name,
                );
            }

            // Manche Installationen antworten mit einem JSON-Objekt, andere mit dem nackten
            // Token als JSON-String.
            $neu = $antwort->json('token') ?? trim($antwort->body(), '"');

            if (! is_string($neu) || $neu === '') {
                throw new EasyVereinException(
                    'easyVerein-Token-Refresh lieferte kein Token.',
                    $instanz->name,
                );
            }

            $this->speicher->speichern($instanz, $neu);

            Event::dispatch(new TokenErneuert($instanz->name));
        } catch (Throwable $e) {
            $this->fehler('easyVerein-Token-Refresh fehlgeschlagen', $instanz, $e);

            throw $e;
        }
    }

    private function urlErlaubt(Instanz $instanz, string $url): bool
    {
        $teile = parse_url($url);

        return ($teile['scheme'] ?? null) === 'https'
            && ($teile['host'] ?? null) === $instanz->host();
    }

    /**
     * Fehlersenke: ein Event, kein fest verdrahtetes Log. Bewusst **kein** Token und
     * **keine** Personendaten – nur die Instanz.
     */
    private function fehler(string $nachricht, Instanz $instanz, ?Throwable $e = null): void
    {
        Event::dispatch(new EasyVereinFehler($nachricht, $instanz->name, $e));
    }
}
