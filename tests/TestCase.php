<?php

namespace Thoule\EasyVerein\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Orchestra\Testbench\TestCase as Basis;
use Thoule\EasyVerein\EasyVereinServiceProvider;

abstract class TestCase extends Basis
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ohne das würde jeder Paginierungstest die echte Rate-Limit-Pause abwarten.
        Sleep::fake();

        // Jeder Aufruf ohne passenden Fake wirft, statt echt hinauszugehen.
        //
        // **Warum das hier steht und nicht als Detail gilt.** Genau dieser Schutz fehlte in
        // der Vorlage: Ein Fake-Muster ohne `https://` matcht nicht, und ein Test, der
        // prüft, dass *nichts* passiert, ist dann trivial grün – ohne Treffer passiert
        // ohnehin nichts. Mehrere Ablehnungstests waren dadurch gegenstandslos, ohne dass
        // es je aufgefallen wäre.
        Http::preventStrayRequests();
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [EasyVereinServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('easyverein.pause_sekunden', 0);
        $app['config']->set('easyverein.instanzen', [[
            'name' => 'hauptverein',
            'basis_url' => 'https://easyverein.com/api/stable/',
            'api_key' => 'start-key',
        ]]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
