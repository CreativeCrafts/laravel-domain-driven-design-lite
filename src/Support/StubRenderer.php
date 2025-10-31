<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Support;

final class StubRenderer
{
    /**
     * Renders a stub by replacing {{Var}} tokens with provided values.
     * No logic—simple, predictable replacements.
     */
    public function render(string $stubContents, array $vars): string
    {
        $content = $stubContents;
        foreach ($vars as $k => $v) {
            $content = str_replace('{{'.$k.'}}', (string) $v, $content);
        }
        return $content;
    }
}
