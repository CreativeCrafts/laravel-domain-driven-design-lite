<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Support;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use RuntimeException;

final class NamespaceRewriter
{
    public function rewrite(string $code, string $newNamespace): string
    {
        if (!class_exists(ParserFactory::class)) {
            throw new RuntimeException('nikic/php-parser is required to rewrite namespaces.');
        }

        $parser = (new ParserFactory())->createForHostVersion();

        try {
            $ast = $parser->parse($code) ?? [];
        } catch (Error $e) {
            throw new RuntimeException('Failed to parse PHP code for namespace rewrite: ' . $e->getMessage());
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(
            new class ($newNamespace) extends NodeVisitorAbstract {
                public function __construct(private readonly string $ns)
                {
                }

                public function enterNode(Node $node): null
                {
                    if ($node instanceof Namespace_) {
                        $node->name = new Node\Name($this->ns);
                    }

                    return null;
                }
            }
        );

        $newAst = $traverser->traverse($ast);

        return (new Standard())->prettyPrintFile($newAst);
    }
}
