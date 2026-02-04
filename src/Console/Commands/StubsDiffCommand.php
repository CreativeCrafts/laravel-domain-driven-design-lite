<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console\Commands;

use CreativeCrafts\DomainDrivenDesignLite\Console\BaseCommand;
use CreativeCrafts\DomainDrivenDesignLite\Support\StubDiff;
use JsonException;

final class StubsDiffCommand extends BaseCommand
{
    protected $signature = 'ddd-lite:stubs:diff
        {--base= : Base stubs root (defaults to package stubs)}
        {--custom= : Custom stubs root (defaults to app stubs)}
        {--json : Output JSON}';

    protected $description = 'Compare published stubs with the package defaults.';

    /**
     * @throws JsonException
     */
    public function handle(): int
    {
        $this->prepare();

        $base = $this->getStringOption('base')
            ?? __DIR__ . '/../../../stubs/ddd-lite';
        $custom = $this->getStringOption('custom') ?? base_path('stubs/ddd-lite');
        $asJson = $this->option('json') === true;

        $rows = (new StubDiff())->compare($base, $custom);

        if ($asJson) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->warnBox('No stubs found to compare.');
            return self::SUCCESS;
        }

        $this->headline('DDD-Lite stubs diff');
        $this->line(str_pad('STATUS', 10) . 'PATH');
        $this->line(str_repeat('-', 60));
        foreach ($rows as $row) {
            $this->line(str_pad($row['status'], 10) . $row['path']);
        }

        return self::SUCCESS;
    }
}
