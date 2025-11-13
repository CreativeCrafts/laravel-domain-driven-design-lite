<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Support\Concerns;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Trait ConsoleUx
 * This trait is intended to be used within a Laravel Command context,
 * which provides the `line()` method.
 */
trait ConsoleUx
{
    protected function headline(string $text): void
    {
        /** @phpstan-ignore-next-line */
        $this->line('');
        /** @phpstan-ignore-next-line */
        $this->line('<info>== ' . $text . ' ==</info>');
    }

    protected function note(string $text): void
    {
        /** @phpstan-ignore-next-line */
        $this->line('<comment>• ' . $text . '</comment>');
    }

    /**
     * Key/Value in two columns (delegates to BaseCommand::twoColumn if present).
     */
    protected function kv(string $key, string $value): void
    {
        if (method_exists($this, 'twoColumn')) {
            /** @phpstan-ignore-next-line */
            $this->twoColumn($key, $value);
            return;
        }

        /** @phpstan-ignore-next-line */
        $this->line(sprintf('%-24s %s', $key, $value));
    }

    /**
     * Render a short "summary" block (title + KVs).
     *
     * @param array<string,string> $keyValues
     */
    protected function summary(string $title, array $keyValues): void
    {
        $this->headline($title);
        foreach ($keyValues as $k => $v) {
            $this->kv((string)$k, (string)$v);
        }
        /** @phpstan-ignore-next-line */
        $this->line('');
    }

    /**
     * Optional progress wrapper (no-op in non-interactive or quiet mode).
     * @template TReturn
     *
     * @param int $max
     * @param callable(ProgressBar):TReturn $operation
     * @return TReturn
     */
    protected function withProgress(int $max, callable $operation)
    {
        $io = new SymfonyStyle($this->input, $this->output);

        if (!$this->output->isQuiet() && $this->input->isInteractive() && $max > 0) {
            $progress = $io->createProgressBar($max);
            $progress->setFormat(' [%bar%] %percent:3s%% %message%');
            $progress->start();

            try {
                /** @var TReturn $result */
                $result = $operation($progress);
                /** @phpstan-ignore-next-line */
                $progress->finish();
                /** @phpstan-ignore-next-line */
                $this->line('');
                return $result;
            } finally {
                if ($progress->getMaxSteps() > 0 && $progress->getProgress() < $progress->getMaxSteps()) {
                    /** @phpstan-ignore-next-line */
                    $progress->finish();
                    /** @phpstan-ignore-next-line */
                    $this->line('');
                }
            }
        }

        /** @var TReturn $result */
        $result = $operation(new ProgressBar($this->output, 0));
        return $result;
    }

    protected function successBox(string $message): void
    {
        /** @phpstan-ignore-next-line */
        $this->line('');
        /** @phpstan-ignore-next-line */
        $this->line('<info>' . $message . '</info>');
        /** @phpstan-ignore-next-line */
        $this->line('');
    }

    protected function warnBox(string $message): void
    {
        /** @phpstan-ignore-next-line */
        $this->line('');
        /** @phpstan-ignore-next-line */
        $this->line('<comment>' . $message . '</comment>');
        /** @phpstan-ignore-next-line */
        $this->line('');
    }
}
