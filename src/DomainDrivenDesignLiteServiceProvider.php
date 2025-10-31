<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite;

use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\DoctorCommand;
use CreativeCrafts\DomainDrivenDesignLite\Console\Commands\ModuleScaffoldCommand;
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
            ]);
    }

    public function packageBooted(): void
    {
        $this->publishes([
            __DIR__ . '/../stubs/ddd-lite' => base_path('stubs/ddd-lite'),
        ], 'ddd-lite-stubs');
    }
}
