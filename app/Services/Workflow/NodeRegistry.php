<?php

namespace App\Services\Workflow;

use App\Nodes\NodeInterface;
use App\Nodes\WorkflowContext;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Registry semua node yang terdaftar.
 * Saat boot, semua class di app/Nodes yang mengimplementasikan NodeInterface otomatis didaftarkan.
 */
class NodeRegistry
{
    protected array $nodes = [];

    public function __construct()
    {
        $this->discover();
    }

    protected function discover(): void
    {
        $dir = APPPATH . 'Nodes';

        if (! is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($dir) + 1);
            $class = 'App\\Nodes\\' . str_replace(['/', '\\'], '\\', substr($relative, 0, -4));
            $class = str_replace('.php', '', $class);

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait()) {
                continue;
            }

            if (! $reflection->implementsInterface(NodeInterface::class)) {
                continue;
            }

            /** @var NodeInterface $instance */
            $instance = new $class();
            $this->nodes[$instance->getType()] = $instance;
        }
    }

    public function register(NodeInterface $node): void
    {
        $this->nodes[$node->getType()] = $node;
    }

    public function get(string $type): ?NodeInterface
    {
        return $this->nodes[$type] ?? null;
    }

    public function all(): array
    {
        return $this->nodes;
    }

    /**
     * Daftar node untuk GET /api/nodes (tanpa implementasi, hanya metadata + schema).
     */
    public function toApi(): array
    {
        $out = [];
        foreach ($this->nodes as $node) {
            $out[] = [
                'type'        => $node->getType(),
                'name'        => $node->getName(),
                'category'    => $node->getCategory(),
                'color'       => $node->getColor(),
                'icon'        => $node->getIcon(),
                'description' => $node->getDescription(),
                'parameters'  => $node->getParameters(),
                'examples'    => $node->getExamples(),
                'exampleOutput' => $node->getExampleOutput(),
                'outputs'     => $node->getOutputs(),
                'isTrigger'   => $node->isTrigger(),
            ];
        }

        return $out;
    }
}
