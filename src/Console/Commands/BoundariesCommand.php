<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console\Commands;

use CreativeCrafts\DomainDrivenDesignLite\Console\BaseCommand;
use Illuminate\Support\Facades\Artisan;
use JsonException;

final class BoundariesCommand extends BaseCommand
{
    protected $signature = 'ddd-lite:boundaries
        {--config=deptrac.yaml : Deptrac configuration file to use}
        {--bin=vendor/bin/deptrac : Path to deptrac executable}
        {--json : Emit machine-readable JSON summary}
        {--strict : Treat uncovered as failure}
        {--stdin-report= : Deptrac JSON report payload (skips invoking deptrac)}
        {--fail-on=violations : Failure policy: violations|errors|uncovered|any}';

    protected $description = 'Run architectural boundary checks (Deptrac) and summarize results.';

    /**
     * @throws JsonException
     */
    public function handle(): int
    {
        $this->prepare();

        $args = [
            '--config' => $this->getStringOption('config') ?? 'deptrac.yaml',
            '--bin' => $this->getStringOption('bin') ?? 'vendor/bin/deptrac',
            '--fail-on' => $this->getStringOption('fail-on') ?? 'violations',
        ];

        if ($this->option('json') === true) {
            $args['--json'] = true;
        }
        if ($this->option('strict') === true) {
            $args['--strict'] = true;
        }
        $stdinReport = $this->getStringOption('stdin-report');
        if ($stdinReport !== null) {
            $args['--stdin-report'] = $stdinReport;
        }

        $exit = Artisan::call('ddd-lite:doctor:domain', $args);
        $this->line(Artisan::output());
        return $exit;
    }
}
