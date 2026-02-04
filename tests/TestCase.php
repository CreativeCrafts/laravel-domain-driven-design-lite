<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;
use CreativeCrafts\DomainDrivenDesignLite\DomainDrivenDesignLiteServiceProvider;

class TestCase extends Orchestra
{
    /** @var resource|null */
    private $testLockHandle = null;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->shouldSerializeTests()) {
            $lockPath = base_path('storage/ddd-lite-test.lock');
            $lockDir = dirname($lockPath);
            if (!is_dir($lockDir)) {
                @mkdir($lockDir, 0775, true);
            }
            $handle = fopen($lockPath, 'c');
            if (is_resource($handle)) {
                // Serialize parallel test processes to avoid shared filesystem races.
                flock($handle, LOCK_EX);
                $this->testLockHandle = $handle;
            }
        }

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'CreativeCrafts\\DomainDrivenDesignLite\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function tearDown(): void
    {
        if (is_resource($this->testLockHandle)) {
            flock($this->testLockHandle, LOCK_UN);
            fclose($this->testLockHandle);
            $this->testLockHandle = null;
        }

        parent::tearDown();
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');

        /*
         foreach (\Illuminate\Support\Facades\File::allFiles(__DIR__ . '/../database/migrations') as $migration) {
            (include $migration->getRealPath())->up();
         }
         */
    }

    protected function getPackageProviders($app)
    {
        return [
            DomainDrivenDesignLiteServiceProvider::class,
        ];
    }

    private function shouldSerializeTests(): bool
    {
        return ((string)getenv('TEST_TOKEN')) !== ''
            || ((string)getenv('PARATEST')) !== ''
            || ((string)getenv('PEST_PARALLEL')) !== '';
    }
}
