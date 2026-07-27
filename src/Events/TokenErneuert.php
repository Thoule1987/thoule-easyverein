<?php

namespace Thoule\EasyVerein\Events;

/**
 * Ein API-Key wurde rotiert. Das alte Token ist ab diesem Moment ungültig.
 *
 * Nützlich für Betriebsanzeigen („letzte Rotation: …"), Monitoring und um eine zweite
 * Installation zu erkennen, die denselben Key verwendet – die merkt sonst erst am nächsten
 * 401, dass ihr das Token unter den Füssen weggezogen wurde.
 *
 * Trägt **kein** Token.
 */
final readonly class TokenErneuert
{
    public function __construct(public string $instanz) {}
}
