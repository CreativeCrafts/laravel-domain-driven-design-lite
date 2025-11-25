<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite;

use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\BindContractCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\ConvertCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\DoctorCiCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\DoctorCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\DoctorDomainCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\MakeActionCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\MakeAggregateRootCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\MakeContractCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\MakeControllerCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\MakeDtoCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\MakeMigrationCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\MakeModelCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\MakeProviderCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\MakeQueryAggregatorCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\MakeQueryBuilderCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\MakeQueryCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\MakeRepositoryCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\MakeRequestCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\MakeValueObjectCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\ManifestListCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\ManifestShowCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\ModuleScaffoldCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\PublishQualityCommand;
use Illuminate\Support\ServiceProvider;

final class DomainDrivenDesignLiteServiceProvider extends ServiceProvider
{
    /**
     * Register bindings.
     * Keep this intentionally minimal: the package is dev/CI tooling only.
     */
    public function register(): void
    {
        // No container bindings required for now.
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            // All functionality is console-only; nothing to do in HTTP runtime.
            return;
        }

        $this->bootForConsole();
    }

    /**
     * Console-only bootstrapping: commands & publish groups.
     */
    protected function bootForConsole(): void
    {
        $this->registerCommands();
        $this->registerPublishes();
    }

    /**
     * Register all ddd-lite artisan commands.
     */
    protected function registerCommands(): void
    {
        $this->commands([
            ModuleScaffoldCommand::class,
            DoctorCommand::class,
            DoctorCiCommand::class,

            MakeActionCommand::class,
            MakeDtoCommand::class,
            MakeContractCommand::class,
            MakeRepositoryCommand::class,
            MakeModelCommand::class,
            MakeRequestCommand::class,
            MakeControllerCommand::class,
            MakeMigrationCommand::class,
            MakeProviderCommand::class,
            MakeQueryCommand::class,
            MakeQueryBuilderCommand::class,
            MakeQueryAggregatorCommand::class,
            MakeValueObjectCommand::class,
            MakeAggregateRootCommand::class,

            ManifestListCommand::class,
            ManifestShowCommand::class,

            BindContractCommand::class,
            ConvertCommand::class,
            PublishQualityCommand::class,
            DoctorDomainCommand::class,
        ]);
    }

    /**
     * Register publishable resources (stubs, schemas).
     */
    protected function registerPublishes(): void
    {
        // Core DDD-Lite stubs
        $this->publishes([
            __DIR__ . '/../stubs/ddd-lite' => base_path('stubs/ddd-lite'),
        ], 'ddd-lite-stubs');

        // Doctor JSON schema
        $this->publishes([
            __DIR__ . '/../stubs/doctor/schema/doctor-report.schema.json' =>
                base_path('stubs/ddd-lite/doctor-report.schema.json'),
        ], 'ddd-lite-schemas');

        // Optional: broader "ddd-lite" publish group.
        // Keeping behaviour compatible while ensuring the file path is valid.
        $this->publishes([
            __DIR__ . '/../stubs/ddd-lite' => base_path('stubs/ddd-lite'),
            __DIR__ . '/../stubs/doctor/schema/doctor-report.schema.json' =>
                base_path('stubs/ddd-lite/doctor-report.schema.json'),
        ], 'ddd-lite');
    }
}
