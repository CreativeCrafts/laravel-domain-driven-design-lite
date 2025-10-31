<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console\Commands;

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
    protected Filesystem $files;
    protected SafeFilesystem $safe;
    protected StubRenderer $renderer;

    /**
     * Initialize the command dependencies and prepare the filesystem and rendering utilities.
     * This method sets up the required services for file operations and stub rendering.
     * It resolves the Filesystem instance from the service container, creates a SafeFilesystem
     * wrapper for safe file operations, and initializes the StubRenderer for template processing.
     *
     * @return void
     */
    protected function prepare(): void
    {
        if (!function_exists('base_path')) {
            throw new RuntimeException('This command must run within a Laravel application context.');
        }
        $this->files = app(Filesystem::class);
        $this->safe = new SafeFilesystem($this->files);
        $this->renderer = new StubRenderer();
    }

    /**
     * Read the contents of a stub file from either the host project or package fallback location.
     * This method attempts to locate and read a stub file by first checking the host project's
     * stubs directory (base_path/stubs/ddd-lite/), and if not found, falls back to the package's
     * internal stubs' directory. This allows projects to override package stubs with custom versions.
     *
     * @param string $stubName The name of the stub file to read (e.g. 'controller.stub')
     * @return string The contents of the stub file
     * @throws RuntimeException If the stub file is not found in either location
     */
    protected function readStub(string $stubName): string
    {
        // host-override first
        $host = base_path("stubs/ddd-lite/{$stubName}");
        if (is_file($host)) {
            return (string)file_get_contents($host);
        }

        // package fallback
        $pkg = __DIR__ . "/../../../stubs/ddd-lite/{$stubName}";
        if (is_file($pkg)) {
            return (string)file_get_contents($pkg);
        }

        throw new RuntimeException("Stub not found: {$stubName}");
    }

    /**
     * Render a stub template with the provided variables.
     * This method reads a stub file by name and processes it through the StubRenderer,
     * replacing placeholders with the provided variable values. It combines the stub
     * reading and rendering operations into a single convenient method.
     *
     * @param string $stubLogicalName
     * @param array $vars An associative array of variables to replace in the stub template,
     *                    where keys are placeholder names and values are their replacements
     * @return string The rendered stub content with all variables replaced
     */
    protected function render(string $stubLogicalName, array $vars): string
    {
        return $this->renderer->render($stubLogicalName, $vars);
    }

    /**
     * Prompt the user for confirmation and exit the command if they decline.
     * This method displays a confirmation question to the user and waits for their response.
     * If the user confirms (answers yes), execution continues normally. If the user declines
     * (answers no), the method displays an 'Aborted.' message and terminates the command
     * with a success exit code.
     *
     * @param string $question The confirmation question to display to the user
     * @return void This method either continues execution or exits the process
     */
    protected function confirmOrExit(string $question): void
    {
        if (!$this->confirm($question)) {
            $this->info('Aborted.');
            exit(self::SUCCESS);
        }
    }

    /**
     * Initialize and begin a new manifest for tracking generated files and operations.
     * This method creates a new Manifest instance that can be used to track files created
     * during command execution. The manifest provides a way to record and later reference
     * all files and operations performed by the command, enabling features like rollback
     * or cleanup operations.
     *
     * @return Manifest A new Manifest instance ready to track file operations
     * @throws RandomException If the manifest initialization fails due to random ID generation issues
     */
    protected function beginManifest(): Manifest
    {
        return Manifest::begin($this->files);
    }

    /**
     * Load an existing manifest by its unique identifier or throw an exception if not found.
     * This method attempts to load a previously created Manifest instance using its unique ID.
     * If the manifest cannot be found or loaded, it throws a RuntimeException with details
     * about the missing manifest. This is useful for commands that need to reference or
     * continue operations from a previous command execution.
     *
     * @param string $id The unique identifier of the manifest to load
     * @return Manifest The loaded Manifest instance containing tracked file operations
     * @throws FileNotFoundException If the manifest file cannot be read from the filesystem
     * @throws JsonException If the manifest file contains invalid JSON data
     * @throws RuntimeException If the manifest with the specified ID does not exist
     */
    protected function loadManifestOrFail(string $id): Manifest
    {
        $m = Manifest::load($this->files, $id);
        if (!$m) {
            throw new RuntimeException("Manifest not found: {$id}");
        }
        return $m;
    }

    protected function twoColumn(string $key, string $value): void
    {
        $this->line(str_pad($key . ':', 14) . $value);
    }

    protected function rel(string $abs): string
    {
        return ltrim(str_replace(base_path(), '', $abs), DIRECTORY_SEPARATOR);
    }
}
