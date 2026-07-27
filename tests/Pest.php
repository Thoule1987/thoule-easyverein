<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Thoule\EasyVerein\Instanz;
use Thoule\EasyVerein\Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in(__DIR__);

function instanz(string $name = 'hauptverein', string $key = 'start-key'): Instanz
{
    return new Instanz($name, 'https://easyverein.com/api/stable/', $key);
}

/**
 * Antwortform der echten API: `results` und `next`, **kein** `count`.
 *
 * @param  list<array<string, mixed>>  $datensaetze
 * @return array<string, mixed>
 */
function seite(array $datensaetze, ?string $next = null): array
{
    return ['current' => 1, 'next' => $next, 'previous' => null, 'results' => $datensaetze];
}
