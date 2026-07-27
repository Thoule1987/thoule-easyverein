<?php

namespace Thoule\EasyVerein\Events;

/**
 * Ein OIDC-Login über easyVerein war erfolgreich; die Userinfo liegt vor.
 *
 * **Wofür.** Die Zuordnung von easyVerein-Gruppen auf App-Rollen ist in jeder App anders
 * (KAST hat ein eigenes Rollen-Model mit Slot-Skopierung, andere nutzen spatie). Statt das
 * ins Paket zu ziehen, reicht der Provider die Gruppen durch und das Paket feuert dieses
 * Event. Wer spatie nutzt, hängt den mitgelieferten Mapper daran; alle anderen ihren
 * eigenen Listener.
 *
 * `$benutzer` ist das App-eigene Model (nicht typisiert, weil das Paket es nicht kennt).
 *
 * @param  list<string>  $gruppen
 */
final readonly class BenutzerAngemeldet
{
    /**
     * @param  list<string>  $gruppen
     * @param  array<string, mixed>  $userinfo
     */
    public function __construct(
        public mixed $benutzer,
        public array $gruppen,
        public array $userinfo = [],
    ) {}
}
