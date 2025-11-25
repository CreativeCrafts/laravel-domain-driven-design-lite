<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console;

use CreativeCrafts\DomainDrivenDesignLite\Support\Concerns\ConsoleUx;
use CreativeCrafts\DomainDrivenDesignLite\Support\Manifest;
use CreativeCrafts\DomainDrivenDesignLite\Support\SafeFilesystem;
use CreativeCrafts\DomainDrivenDesignLite\Support\StubRenderer;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use Random\RandomException;
use RuntimeException;

abstract class BaseCommand extends Command
{
    use ConsoleUx;

    protected Filesystem $files;
    protected SafeFilesystem $safe;
    protected StubRenderer $renderer;

    public function __construct()
    {
        parent::__construct();
        // Initialize with safe defaults; overridden during prepare() in Laravel context
        $this->files = new Filesystem();
        $this->safe = new SafeFilesystem($this->files);
        $this->renderer = new StubRenderer();
    }

    protected function prepare(): void
    {
        if (!function_exists('base_path')) {
            throw new RuntimeException('This command must run within a Laravel application context.');
        }

        $this->files = app(Filesystem::class);
        $this->safe = new SafeFilesystem($this->files);
        $this->renderer = new StubRenderer();
    }

    protected function readStub(string $stubName): string
    {
        $host = base_path("stubs/ddd-lite/{$stubName}");
        if (is_file($host)) {
            return (string)file_get_contents($host);
        }

        $pkg = __DIR__ . "/../../../stubs/ddd-lite/{$stubName}";
        if (is_file($pkg)) {
            return (string)file_get_contents($pkg);
        }

        throw new RuntimeException("Stub not found: {$stubName}");
    }

    /**
     * @param array<string,string> $vars
     */
    protected function render(string $stubLogicalName, array $vars): string
    {
        return $this->renderer->render($stubLogicalName, $vars);
    }

    protected function confirmOrExit(string $question): void
    {
        if (!$this->confirm($question)) {
            $this->info('Aborted.');
            exit(self::SUCCESS);
        }
    }


    /**
     * @throws RandomException
     */
    protected function beginManifest(): Manifest
    {
        return Manifest::begin($this->files);
    }

    protected function twoColumn(string $label, string $value): void
    {
        $this->line(str_pad($label . ':', 16) . $value);
    }

    protected function rel(string $abs): string
    {
        $base = base_path();
        return ltrim(str_replace($base, '', $abs), DIRECTORY_SEPARATOR);
    }

    /**
     * @param string $id
     * @return Manifest
     * @throws JsonException
     * @throws RandomException
     * @throws FileNotFoundException
     */
    protected function loadManifestOrFail(string $id): Manifest
    {
        $fs = app(Filesystem::class);
        return Manifest::load($fs, $id);
    }
}
