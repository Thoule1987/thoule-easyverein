<?php

namespace Thoule\EasyVerein\Socialite;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;
use Thoule\EasyVerein\EasyVereinException;
use Thoule\EasyVerein\Events\EasyVereinFehler;

/**
 * OIDC-Login über easyVerein.
 *
 * ## Warum die Endpunkte fest verdrahtet sind
 *
 * easyVerein serviert **kein** funktionierendes Discovery-Dokument: `.well-known/
 * openid-configuration` antwortet mit 404, was in einer der Apps zu einem 500er auf der
 * Redirect-Route führte. Die drei Endpunkte sind live bestätigt (authorize → 302, token →
 * 405 ohne POST, userinfo → 401 ohne Token) und stehen in `config('easyverein.oidc')`.
 *
 * ## PKCE
 *
 * Ist eingeschaltet. Bisher nutzte nur eine der vier Apps es – ohne PKCE ist ein
 * abgefangener Authorization Code für sich genommen einlösbar.
 *
 * ## Gemessene Userinfo-Felder (26.07.2026, drei echte Logins)
 *
 * `openid` liefert auf oberster Ebene: `sub`, `name`, `username`, `given_name`,
 * `family_name`, `email`, `groups`, `chairman_group`, `org_short`, `sub_orgs`.
 * `profile` ergänzt ein gleichnamiges Objekt mit `name`, `family_name`, `picture`,
 * `joinDate`, `updated_at`.
 *
 * Das Paket **mappt nichts weg**: Die vollständige Antwort steht über `getRaw()` bereit,
 * weil jede App andere Felder braucht.
 */
class EasyVereinProvider extends AbstractProvider implements ProviderInterface
{
    protected $scopeSeparator = ' ';

    protected $usesPKCE = true;

    /** @var list<string> */
    protected $scopes = ['openid', 'profile'];

    public function __construct(...$argumente)
    {
        parent::__construct(...$argumente);

        /** @var list<string> $scopes */
        $scopes = config('easyverein.oidc.scopes', ['openid', 'profile']);

        // Konfigurierbar, weil ein Scope beim easyVerein-Client freigeschaltet sein muss:
        // Fehlt die Freischaltung, scheitert der Login mit „The requested scope was not
        // registered" – und zwar je Client, also für dev und prod getrennt. Ohne diesen
        // Schalter wäre der einzige Ausweg ein Release.
        if ($scopes !== []) {
            $this->scopes = $scopes;
        }
    }

    /**
     * @param  string  $state
     */
    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase(
            (string) config('easyverein.oidc.authorize_url'),
            $state,
        );
    }

    protected function getTokenUrl(): string
    {
        return (string) config('easyverein.oidc.token_url');
    }

    /**
     * @param  string  $token
     * @return array<string, mixed>
     */
    protected function getUserByToken($token): array
    {
        $antwort = Http::timeout(5)
            ->withToken($token)
            ->acceptJson()
            ->get((string) config('easyverein.oidc.userinfo_url'));

        if ($antwort->failed()) {
            // Kein Token und keine Userinfo ins Log – nur der Status.
            Event::dispatch(new EasyVereinFehler(
                "easyVerein-Userinfo nicht erreichbar ({$antwort->status()}).",
            ));

            throw new EasyVereinException('easyVerein-Userinfo nicht erreichbar.');
        }

        return (array) $antwort->json();
    }

    /**
     * @param  array<string, mixed>  $user
     */
    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => $user['sub'] ?? null,
            'email' => $user['email'] ?? null,
            'name' => $user['name'] ?? $user['email'] ?? null,
            'nickname' => $user['username'] ?? null,
            'avatar' => $this->profilbildUrl($user),
        ]);
    }

    /**
     * Die easyVerein-Gruppen der angemeldeten Person, als flache Liste.
     *
     * Der Provider **wertet sie nicht aus** – die Zuordnung auf App-Rollen ist in jeder App
     * anders. Wer sie braucht, liest sie hier heraus und feuert `BenutzerAngemeldet`.
     *
     * @param  array<string, mixed>  $userinfo
     * @return list<string>
     */
    public static function gruppen(array $userinfo): array
    {
        $gruppen = $userinfo['groups'] ?? [];

        if (! is_array($gruppen)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $g): string => is_string($g) ? $g : '', $gruppen),
            fn (string $g): bool => $g !== '',
        ));
    }

    /**
     * @param  array<string, mixed>  $user
     */
    private function profilbildUrl(array $user): ?string
    {
        $profil = $user['profile'] ?? null;

        if (! is_array($profil)) {
            return null;
        }

        $url = $profil['picture'] ?? null;

        // Nur absolute https-URLs. Ein relativer oder http-Wert liefe beim späteren Abruf
        // ins Leere oder ginge unverschlüsselt; ein file://- oder gopher://-Wert wäre eine
        // SSRF-Einladung. Fehlt der profile-Scope oder hat die Person kein Bild, bleibt der
        // Wert null und der Login läuft unverändert durch.
        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            return null;
        }

        return $url;
    }
}
