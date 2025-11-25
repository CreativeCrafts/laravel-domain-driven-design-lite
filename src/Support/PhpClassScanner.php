<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Support;

final class PhpClassScanner
{
    public function fqcnFromFile(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $code = (string)file_get_contents($path);
        $tokens = token_get_all($code);

        $namespace = '';
        $class = '';
        $collectNs = false;

        foreach ($tokens as $i => $token) {
            if (is_array($token)) {
                $id = $token[0];

                if ($id === T_NAMESPACE) {
                    $namespace = '';
                    $collectNs = true;
                } elseif ($collectNs && ($id === T_STRING || $id === T_NAME_QUALIFIED)) {
                    $namespace .= $token[1];
                } elseif ($collectNs && $id === T_NS_SEPARATOR) {
                    $namespace .= '\\';
                } elseif ($id === T_CLASS) {
                    $class = $this->readNextStringToken($tokens, $i + 1);
                    break;
                }
            } elseif ($collectNs && $token === ';') {
                $collectNs = false;
            }
        }

        if ($class === '') {
            return null;
        }

        return $namespace !== '' ? $namespace . '\\' . $class : $class;
    }

    public function shortClassFromFile(string $path): ?string
    {
        $fqcn = $this->fqcnFromFile($path);
        if ($fqcn === null) {
            return null;
        }
        $pos = strrpos($fqcn, '\\');
        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    public function namespaceFromFile(string $path): ?string
    {
        $fqcn = $this->fqcnFromFile($path);
        if ($fqcn === null) {
            return null;
        }
        $pos = strrpos($fqcn, '\\');
        return $pos === false ? '' : substr($fqcn, 0, $pos);
    }

    /**
     * @param array<int, mixed> $tokens
     */
    private function readNextStringToken(array $tokens, int $start): string
    {
        $count = count($tokens);
        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_STRING) {
                $val = $token[1] ?? null;
                return is_string($val) ? $val : '';
            }
        }
        return '';
    }
}
