<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Support;

final class PhpUseEditor
{
    public function ensureImports(string $code, array $imports): string
    {
        $imports = array_values(array_unique(array_filter($imports)));
        if ($imports === []) {
            return $code;
        }

        $pos = strpos($code, "\nclass ");
        $pos = $pos === false ? strlen($code) : $pos;

        $namespacePos = strpos($code, "\nnamespace ");
        $nsEnd = $namespacePos === false ? 0 : strpos($code, ";\n", $namespacePos);
        $insertAfter = $nsEnd === false ? 0 : $nsEnd + 2;

        $before = substr($code, 0, $insertAfter);
        $after = substr($code, $insertAfter);

        $existing = [];
        if (preg_match_all('/^use\s+([^;]+);/m', $code, $m)) {
            foreach ($m[1] as $u) {
                $existing[] = trim($u);
            }
        }

        $toAdd = [];
        foreach ($imports as $imp) {
            if (!in_array($imp, $existing, true)) {
                $toAdd[] = 'use ' . $imp . ';';
            }
        }

        if ($toAdd === []) {
            return $code;
        }

        $useBlock = implode(PHP_EOL, $toAdd) . PHP_EOL;
        return $before . $useBlock . $after;
    }
}
