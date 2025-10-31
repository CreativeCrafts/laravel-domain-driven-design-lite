<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Support;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final readonly class AppBootstrapEditor
{
    public function __construct(
        private Filesystem $fs = new Filesystem()
    ) {
    }

    /**
     * Ensure the module provider is registered inside the Application::configure(...) fluent chain
     * via ->withProviders([...]) in bootstrap/app.php (AFTER configure(...), BEFORE other chained calls).
     * - Idempotent: if FQCN is already present, no-op.
     * - Appends to existing withProviders([...]) if found; otherwise injects a new one right after configure(...).
     * - Tracks a backup in the Manifest for rollback.
     */
    public function ensureModuleProvider(Manifest $manifest, string $module, ?string $providerShortName = null): void
    {
        $providerShortName ??= $module . 'ServiceProvider';
        $fqcn = "Modules\\{$module}\\App\\Providers\\{$providerShortName}::class";

        $path = base_path('bootstrap/app.php');
        if (!$this->fs->exists($path)) {
            throw new RuntimeException('bootstrap/app.php not found.');
        }

        $original = (string)$this->fs->get($path);

        // Already present anywhere? Nothing to do.
        if (str_contains($original, $fqcn)) {
            return;
        }

        // 1) Locate "return Application::configure" start
        $needle = 'return Application::configure';
        $retPos = strpos($original, $needle);
        if ($retPos === false) {
            throw new RuntimeException('Could not locate "return Application::configure" in bootstrap/app.php.');
        }

        // 2) Find the opening "(" of Application::configure(...)
        $openParenPos = strpos($original, '(', $retPos);
        if ($openParenPos === false) {
            throw new RuntimeException('Could not locate opening "(" of Application::configure(...).');
        }

        // 3) Find the matching closing ")" for Application::configure(...) by balancing parentheses.
        $closeParenPos = $this->findMatchingParen($original, $openParenPos);
        if ($closeParenPos === null) {
            throw new RuntimeException('Could not match closing parenthesis of Application::configure(...).');
        }

        // The "configure(...)" call ends immediately after the matching ')'
        $configureEnd = $closeParenPos + 1;

        // 4) Split the tail of the fluent chain (from after configure(...) to ->create())
        $tail = $this->slice($original, $configureEnd);

        $createPos = strpos($tail, '->create()');
        if ($createPos === false) {
            throw new RuntimeException('Could not locate ->create() in bootstrap/app.php.');
        }

        $chainBeforeCreate = $this->slice($tail, 0, $createPos);
        $chainAfterCreate = $this->slice($tail, $createPos);

        // 5) Either append to an existing ->withProviders([...]) or inject a new one
        $withProvidersPattern = '/->withProviders\s*\(\s*\[(.*?)\]\s*\)/s';
        $wpMatch = [];
        if (preg_match($withProvidersPattern, $chainBeforeCreate, $wpMatch, PREG_OFFSET_CAPTURE) === 1) {
            // Append our FQCN to the existing list
            $wpBlockStart = (int)$wpMatch[0][1];
            $wpBlockLen = strlen((string)$wpMatch[0][0]);
            $listBody = (string)$wpMatch[1][0];

            $listBodyTrim = rtrim($listBody);
            $newListBody = $listBodyTrim . (trim($listBodyTrim) !== '' ? PHP_EOL : '') . "        {$fqcn},";

            $existingWpBlock = $this->slice($chainBeforeCreate, $wpBlockStart, $wpBlockLen);
            $newWpBlock = str_replace($listBody, $newListBody, $existingWpBlock);

            $prefix = $this->slice($chainBeforeCreate, 0, $wpBlockStart);
            $suffix = $this->slice($chainBeforeCreate, $wpBlockStart + $wpBlockLen);

            $newChainBeforeCreate = $prefix . $newWpBlock . $suffix;
        } else {
            // Inject a fresh ->withProviders([...]) RIGHT AFTER configure(...), BEFORE any other chain call.
            // Keep indentation consistent with Laravel's bootstrap/app.php.
            $injection = "    ->withProviders([\n        {$fqcn},\n    ])\n";
            $newChainBeforeCreate = $injection . $chainBeforeCreate;
        }

        // 6) Stitch the file back together: prefix (up to end of configure(...)) + new chain + remainder
        $prefixPart = $this->slice($original, 0, $configureEnd);
        $updated = $prefixPart . $newChainBeforeCreate . $chainAfterCreate;

        // 7) Track backup & write
        $backup = storage_path('app/ddd-lite_scaffold/backups/bootstrap_app_' . sha1($path) . '.bak');
        $this->fs->ensureDirectoryExists(dirname($backup));
        $this->fs->put($backup, $original);
        $manifest->trackUpdate('bootstrap/app.php', $backup);

        $this->fs->put($path, $updated);
    }

    /**
     * Remove any standalone "$app->withProviders([...]);" statements (outside the chain).
     * Idempotent: only writes when a match is found. Tracked in Manifest for rollback.
     */
    public function removeStandaloneWithProviders(Manifest $manifest): void
    {
        $path = base_path('bootstrap/app.php');
        if (!$this->fs->exists($path)) {
            throw new RuntimeException('bootstrap/app.php not found.');
        }

        $original = (string)$this->fs->get($path);

        // Remove any "$app->withProviders([...]);" — those do not belong in Laravel 12
        $pattern = '/\$app\s*->withProviders\s*\(\s*\[(.*?)\]\s*\)\s*;?/s';
        $updated = (string)preg_replace($pattern, '', $original);

        if ($updated !== $original) {
            $backup = storage_path('app/ddd-lite_scaffold/backups/bootstrap_app_' . sha1($path) . '_rmwp.bak');
            $this->fs->ensureDirectoryExists(dirname($backup));
            $this->fs->put($backup, $original);
            $manifest->trackUpdate('bootstrap/app.php', $backup);

            $this->fs->put($path, $updated);
        }
    }

    public function ensureRoutingKeys(Manifest $manifest, array $keyToValue): void
    {
        $path = base_path('bootstrap/app.php');
        if (!$this->fs->exists($path)) {
            throw new RuntimeException('bootstrap/app.php not found.');
        }

        $src = (string)$this->fs->get($path);

        $retPos = strpos($src, 'return Application::configure');
        if ($retPos === false) {
            throw new RuntimeException('Could not locate "return Application::configure" in bootstrap/app.php.');
        }
        $openParenPos = strpos($src, '(', $retPos);
        if ($openParenPos === false) {
            throw new RuntimeException('Could not locate opening "(" of Application::configure(...).');
        }
        $closeParenPos = $this->findMatchingParen($src, $openParenPos);
        if ($closeParenPos === null) {
            throw new RuntimeException('Could not match closing parenthesis of Application::configure(...).');
        }
        $configureEnd = $closeParenPos + 1;

        $tail = $this->slice($src, $configureEnd);
        $createPos = strpos($tail, '->create()');
        if ($createPos === false) {
            throw new RuntimeException('Could not locate ->create() in bootstrap/app.php.');
        }

        $chainBeforeCreate = $this->slice($tail, 0, $createPos);
        $chainAfterCreate = $this->slice($tail, $createPos);

        $withPos = strpos($chainBeforeCreate, '->withRouting(');

        if ($withPos === false) {
            $injection = "    ->withRouting(\n";
            foreach ($keyToValue as $k => $v) {
                $injection .= "        {$k}: {$v},\n";
            }
            $injection .= "    )\n";
            $newChain = $injection . $chainBeforeCreate;

            $updated = $this->slice($src, 0, (int)$configureEnd) . $newChain . $chainAfterCreate;

            $backup = storage_path('app/ddd-lite_scaffold/backups/bootstrap_app_' . sha1($path) . '_routing.bak');
            $this->fs->ensureDirectoryExists(dirname($backup));
            $this->fs->put($backup, $src);
            $manifest->trackUpdate('bootstrap/app.php', $backup);

            $this->fs->put($path, $updated);
            return;
        }

        $absWithPos = $configureEnd + $withPos;
        $open = strpos($src, '(', $absWithPos);
        if ($open === false) {
            throw new RuntimeException('Could not locate "(" of withRouting(...).');
        }
        $close = $this->findMatchingParen($src, $open);
        if ($close === null) {
            throw new RuntimeException('Could not match closing parenthesis of withRouting(...).');
        }

        $args = $this->slice($src, $open + 1, $close - ($open + 1));
        $argsTrim = rtrim($args);

        $missing = array_filter($keyToValue, static function ($k) use ($args) {
            return !str_contains($args, $k . ':');
        }, ARRAY_FILTER_USE_KEY);
        if ($missing === []) {
            return;
        }

        $needsComma = $argsTrim !== '' && !str_ends_with($argsTrim, ',');
        $append = '';
        if ($needsComma) {
            $append .= ",\n";
        } elseif ($argsTrim !== '') {
            $append .= "\n";
        }
        foreach ($missing as $k => $v) {
            $append .= "        {$k}: {$v},\n";
        }

        $newArgs = $args . $append;
        $updated = $this->slice($src, 0, $open + 1) . $newArgs . $this->slice($src, $close);

        $backup = storage_path('app/ddd-lite_scaffold/backups/bootstrap_app_' . sha1($path) . '_routing.bak');
        $this->fs->ensureDirectoryExists(dirname($backup));
        $this->fs->put($backup, $src);
        $manifest->trackUpdate('bootstrap/app.php', $backup);

        $this->fs->put($path, $updated);
    }


    /**
     * Find the index of the matching ')' for the '(' at $openPos.
     * Handles nested parentheses and basic string literals to avoid false matches inside quotes.
     *
     * @return int|null Returns the offset of the matching ')' or null if not found.
     */
    private function findMatchingParen(string $src, int $openPos): ?int
    {
        $len = strlen($src);
        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $escaped = false;

        for ($i = $openPos; $i < $len; $i++) {
            $ch = $src[$i];

            // handle escapes inside quotes
            if ($inSingle || $inDouble) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($inSingle && $ch === "'") {
                    $inSingle = false;
                } elseif ($inDouble && $ch === '"') {
                    $inDouble = false;
                }
                continue;
            }

            // not inside quotes
            if ($ch === "'") {
                $inSingle = true;
                continue;
            }
            if ($ch === '"') {
                $inDouble = true;
                continue;
            }

            if ($ch === '(') {
                $depth++;
                continue;
            }
            if ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i; // matching ')'
                }
            }
        }

        return null;
    }

    /**
     * Safe substring helper that guarantees a string result.
     * substr() returns string|false; we normalize to string to keep static analysis happy.
     */
    private function slice(string $src, int $offset, ?int $length = null): string
    {
        return $length === null
            ? (string)substr($src, $offset)
            : (string)substr($src, $offset, $length);
    }
}
