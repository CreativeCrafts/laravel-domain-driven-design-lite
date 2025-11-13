<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite;

use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\BindContractCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\ConvertCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\DoctorCiCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\DoctorCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\DoctorDomainCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\MakeActionCommand;
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
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class DomainDrivenDesignLiteServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-domain-driven-design-lite')
            ->hasCommands([
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
                ManifestListCommand::class,
                ManifestShowCommand::class,
                BindContractCommand::class,
                ConvertCommand::class,
                PublishQualityCommand::class,
                DoctorDomainCommand::class,
            ]);
    }

    public function packageBooted(): void
    {
        $this->publishes([
            __DIR__ . '/../stubs/ddd-lite' => base_path('stubs/ddd-lite'),
        ], 'ddd-lite-stubs');

        $this->publishes([
            __DIR__ . '/../stubs/doctor/schema/doctor-report.schema.json' => base_path('stubs/ddd-lite/doctor-report.schema.json'),
        ], 'ddd-lite-schemas');

        // Optional: include with the broader "ddd-lite" publish group
        $this->publishes([
            __DIR__ . '/../stubs/doctor/schema/doctor-report.schema.json' => base_path('stubs/ddd-lite/doctor-report.schema.json'),
        ], 'ddd-lite');
    }
}
