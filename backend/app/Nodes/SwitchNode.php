<?php

namespace App\Nodes;

class SwitchNode extends AbstractNode
{
    public function getType(): string
    {
        return 'switch';
    }

    public function getName(): string
    {
        return 'Switch';
    }

    public function getCategory(): string
    {
        return 'Flow';
    }

    public function getColor(): string
    {
        return '#7a29e3';
    }

    public function getIcon(): string
    {
        return 'shuffle';
    }

    public function getDescription(): string
    {
        return 'Cabangkan alur ke beberapa output berdasarkan nilai.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'         => 'value',
                'label'       => 'Value / Expression',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => '{{$json.status}}',
            ],
            [
                'key'         => 'rules',
                'label'       => 'Rules (JSON)',
                'type'        => 'json',
                'default'     => '{"success": "main", "error": "fallback"}',
                'description' => 'Object: nilai -> nama output. Tambahkan key "default" untuk fallback.',
            ],
        ];
    }

    public function getOutputs(): array
    {
        return ['main', 'fallback'];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $rules = $params['rules'] ?? [];
        if (is_string($rules)) {
            $rules = json_decode($rules, true) ?: [];
        }
        $rules = (array) $rules;

        $defaultKey = $rules['default'] ?? 'fallback';
        unset($rules['default']);

        $buckets = [];
        foreach ($inputItems as $item) {
            $value = $context->resolve($params['value'] ?? '', $item);
            $key   = array_key_exists((string) $value, $rules) ? $rules[(string) $value] : $defaultKey;
            $buckets[$key][] = $item;
        }

        $outputs = [];
        foreach ($this->getOutputs() as $out) {
            $outputs[$out] = $buckets[$out] ?? [];
        }

        return $outputs;
    }

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: rutekan tiket sesuai prioritas',
    'input' => 
    array (
      'tiket' => 55,
      'prioritas' => 'tinggi',
    ),
    'params' => 
    array (
      'value' => '{{$json.prioritas}}',
      'rules' => '{"tinggi":"main","rendah":"fallback","default":"fallback"}',
    ),
  ),
);
    }

    public function getExampleOutput(): array
    {
        return array (
  'main' => 
  array (
    0 => 
    array (
      'json' => 
      array (
        'tiket' => 55,
        'prioritas' => 'tinggi',
      ),
    ),
  ),
  'fallback' => 
  array (
  ),
);
    }
}
