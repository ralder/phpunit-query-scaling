<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Tests\Integration;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use RuntimeException;
use stdClass;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * @param  Application  $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        $config = $app['config'];
        if (! $config instanceof Repository) {
            throw new RuntimeException('Config repository is not available in the test application.');
        }

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $config->set('database.connections.secondary', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function createWidgetsTable(string $connection = 'testing'): void
    {
        $this->db()->connection($connection)->statement(
            'CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(255))'
        );
    }

    /**
     * @return list<stdClass>
     */
    protected function widgets(string $connection = 'testing'): array
    {
        $widgets = [];
        foreach ($this->db()->connection($connection)->select('SELECT * FROM widgets') as $row) {
            if ($row instanceof stdClass) {
                $widgets[] = $row;
            }
        }

        return $widgets;
    }

    protected function db(): DatabaseManager
    {
        $manager = $this->application()['db'];
        if (! $manager instanceof DatabaseManager) {
            throw new RuntimeException('Database manager is not available in the test application.');
        }

        return $manager;
    }

    protected function dispatcher(): Dispatcher
    {
        $dispatcher = $this->application()['events'];
        if (! $dispatcher instanceof Dispatcher) {
            throw new RuntimeException('Event dispatcher is not available in the test application.');
        }

        return $dispatcher;
    }

    private function application(): Application
    {
        if (! $this->app instanceof Application) {
            throw new RuntimeException('The test application is not bootstrapped.');
        }

        return $this->app;
    }
}
