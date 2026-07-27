<?php

namespace Thoule\EasyVerein\Token;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Persistiertes API-Token je Instanz. Zugriff und Rotation ausschliesslich über
 * DatenbankTokenSpeicher.
 *
 * Die Tabelle heisst per Default `easyverein_tokens` und ist über
 * `config('easyverein.tabelle')` umbenennbar – für Apps, in denen der Name schon vergeben
 * ist.
 *
 * @property string $instanz
 * @property string $token
 * @property Carbon|null $last_refreshed_at
 */
class EasyVereinToken extends Model
{
    use HasUuids;

    protected $fillable = [
        'instanz',
        'token',
        'last_refreshed_at',
    ];

    /**
     * Bewusst nicht als `$table` gesetzt: Der Name kommt aus der Config, damit er zur
     * veröffentlichten Migration passt.
     */
    public function getTable(): string
    {
        return (string) config('easyverein.tabelle', 'easyverein_tokens');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_refreshed_at' => 'datetime',
        ];
    }
}
