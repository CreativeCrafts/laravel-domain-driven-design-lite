<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console\Commands;

use CreativeCrafts\DomainDrivenDesignLite\Console\BaseCommand;
use CreativeCrafts\DomainDrivenDesignLite\Support\BootstrapInspector;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

final class ModulesListCommand extends BaseCommand
{
    protected $signature = 'ddd-lite:modules:list
        {--json : Output JSON}
        {--with-health : Include health checks (provider + routing + PSR-4)}';

    protected $description = 'List modules and optionally show health indicators.';

    /**
     * @throws FileNotFoundException
     * @throws JsonException
     */
    public function handle(): int
    {
        $this->prepare();

        $withHealth = $this->option('with-health') === true;
        $asJson = $this->option('json') === true;

        $modules = $this->discoverModules();

        if ($asJson) {
            $payload = $withHealth ? $this->modulesWithHealth($modules) : $this->modulesPlain($modules);
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        }

        $this->headline('DDD-Lite Modules');
        if ($modules === []) {
            $this->warnBox('No modules found under modules/.');
            return self::SUCCESS;
        }

        if (!$withHealth) {
            foreach ($modules as $m) {
                $this->line('- ' . $m);
            }
            return self::SUCCESS;
        }

        $rows = $this->modulesWithHealth($modules);
        $this->line(str_pad('MODULE', 20) . str_pad('PSR-4', 10) . str_pad('PROVIDER', 12) . 'ROUTING');
        $this->line(str_repeat('-', 56));
        foreach ($rows as $row) {
            $this->line(
                str_pad($row['module'], 20)
                . str_pad($row['psr4'], 10)
                . str_pad($row['provider'], 12)
                . $row['routing']
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int,string>
     */
    private function discoverModules(): array
    {
        $modulesDir = base_path('modules');
        if (!is_dir($modulesDir)) {
            return [];
        }
        $list = [];
        foreach (scandir($modulesDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $modulesDir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($full)) {
                $list[] = $entry;
            }
        }
        sort($list);
        return $list;
    }

    /**
     * @param array<int,string> $modules
     * @return array<int,string>
     */
    private function modulesPlain(array $modules): array
    {
        return $modules;
    }

    /**
     * @param array<int,string> $modules
     * @return array<int,array{module:string,psr4:string,provider:string,routing:string}>
     * @throws FileNotFoundException
     */
    private function modulesWithHealth(array $modules): array
    {
        $inspector = new BootstrapInspector();
        $rows = [];

        $missingRouting = [];
        try {
            $missingRouting = $inspector->missingRoutingKeys(['api', 'channels']);
        } catch (Throwable) {
            $missingRouting = ['parse_error'];
        }

        foreach ($modules as $m) {
            $providerFqcn = "Modules\\{$m}\\App\\Providers\\{$m}ServiceProvider::class";
            $providerInside = false;
            try {
                $providerInside = $inspector->providerInsideConfigureChain($providerFqcn);
            } catch (Throwable) {
                $providerInside = false;
            }

            $psr4 = $this->psr4State($m);
            $rows[] = [
                'module' => $m,
                'psr4' => $psr4,
                'provider' => $providerInside ? 'ok' : 'missing',
                'routing' => $missingRouting === [] ? 'ok' : 'missing:' . implode(',', $missingRouting),
            ];
        }

        return $rows;
    }

    private function psr4State(string $module): string
    {
        $modulesDir = base_path('modules');
        $target = $modulesDir . DIRECTORY_SEPARATOR . $module;
        if (is_dir($target)) {
            return 'ok';
        }
        $lower = $modulesDir . DIRECTORY_SEPARATOR . Str::lower($module);
        if ($lower !== $target && is_dir($lower)) {
            return 'case';
        }
        return 'missing';
    }
}
