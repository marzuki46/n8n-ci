<?php

namespace App\Nodes;

class AggregateNode extends AbstractNode
{
    public function getType(): string
    {
        return 'aggregate';
    }

    public function getName(): string
    {
        return 'Aggregate';
    }

    public function getCategory(): string
    {
        return 'Data';
    }

    public function getColor(): string
    {
        return '#f0a24b';
    }

    public function getIcon(): string
    {
        return 'aggregate';
    }

    public function getDescription(): string
    {
        return 'Gabungkan semua item menjadi satu item.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'     => 'outputFormat',
                'label'   => 'Format Output',
                'type'    => 'select',
                'options' => [
                    ['value' => 'array', 'label' => 'Array (kumpulkan jadi array)'],
                    ['value' => 'single_json', 'label' => 'Single JSON (gabung semua field)'],
                ],
                'default' => 'array',
            ],
            [
                'key'         => 'destinationField',
                'label'       => 'Nama Field',
                'type'        => 'text',
                'default'     => 'items',
                'description' => 'Field tempat menyimpan kumpulan item (mode array).',
            ],
        ];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $format   = $params['outputFormat'] ?? 'array';
        $destField = (string) ($params['destinationField'] ?? 'items');

        if ($format === 'single_json') {
            $merged = [];
            foreach ($inputItems as $item) {
                $data = $this->jsonData($item);
                foreach ($data as $k => $v) {
                    $merged[$k] = $v;
                }
            }

            return ['main' => [$merged]];
        }

        return ['main' => [[
            $destField => $inputItems,
            'count'    => count($inputItems),
        ]]];
    }

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: kumpulkan semua email jadi satu array',
    'input' => 
    array (
      'email' => 'riski@example.com',
    ),
    'params' => 
    array (
      'outputFormat' => 'array',
      'destinationField' => 'daftar_email',
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
        'daftar_email' => 
        array (
          0 => 'riski@example.com',
          1 => 'budi@example.com',
        ),
      ),
    ),
  ),
);
    }
}
