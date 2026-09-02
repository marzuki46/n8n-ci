<?php

namespace App\Nodes;

abstract class AbstractNode implements NodeInterface
{
    public function getDescription(): string
    {
        return '';
    }

    public function getIcon(): string
    {
        return 'circle';
    }

    public function getOutputs(): array
    {
        return ['main'];
    }

    public function isTrigger(): bool
    {
        return false;
    }

    public function getExamples(): array
    {
        return [];
    }

    public function getExampleOutput(): array
    {
        return [];
    }

    /**
     * Tipe pemicu yang didukung trigger node:
     * manual|schedule|webhook. Kosong = hanya untuk manual.
     */
    public function getTriggerKinds(): array
    {
        return [];
    }

    /**
     * Ambil data JSON dari item. Item bisa terbungkus n8n-style
     * ({"json": {...}}) atau data polos. Semua node harus memakai ini
     * agar perilaku konsisten untuk kedua bentuk input.
     */
    protected function jsonData(array $item): array
    {
        if (array_key_exists('json', $item) && is_array($item['json'])) {
            return $item['json'];
        }

        return $item;
    }

    /**
     * Resolve nilai field: dukung ekspresi "{{...}}" maupun nama field polos.
     */
    protected function resolveField(string $expr, array $item, WorkflowContext $context)
    {
        if (strpos($expr, '{{') !== false) {
            return $context->resolve($expr, $item);
        }

        $data = $this->jsonData($item);
        if (array_key_exists($expr, $data)) {
            return $data[$expr];
        }

        return $context->resolve($expr, $item);
    }
}
