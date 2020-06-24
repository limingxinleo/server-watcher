<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace Hyperf\Watcher\Ast;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class RewriteClassNameVisitor extends NodeVisitorAbstract
{
    /**
     * @var Metadata
     */
    protected $metadata;

    public function __construct(Metadata $metadata)
    {
        $this->metadata = $metadata;
    }

    public function leaveNode(Node $node)
    {
        switch ($node) {
            case $node instanceof Node\Stmt\Namespace_:
                $this->metadata->namespace = $node->name->toCodeString();
                return $node;
            case $node instanceof Node\Stmt\Class_:
                $className = $node->name->name;
                $this->metadata->className = $className;
                $this->metadata->proxyName = $className . uniqid();
                return $node;
        }
    }
}
