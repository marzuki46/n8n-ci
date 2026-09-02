<?php

namespace App\Nodes;

class LimitNode extends AbstractNode
{
    public function getType(): string
    {
        return 'limit';
    }

    public function getName(): string
    {
        return 'Limit';
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
        return 'limit';
    }

    public function getDescription(): string
    {
        return 'Batasi jumlah item yang diteruskan.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'     => 'maxItems',
                'label'   => 'Maks Item',
                'type'    => 'number',
                'default' => 10,
                'required' => true,
            ],
            [
                'key'     => 'skipItems',
                'label'   => 'Lewati (skip)',
                'type'    => 'number',
                'default' => 0,
            ],
        ];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $max  = max(0, (int) ($params['maxItems'] ?? 10));
        $skip = max(0, (int) ($params['skipItems'] ?? 0));

        return ['main' => array_slice($inputItems, $skip, $max)];
    }

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: ambil 10 item pertama',
    'input' => 
    array (
      'id' => 1,
      'nama' => 'item',
    ),
    'params' => 
    array (
      'maxItems' => 10,
      'skipItems' => 0,
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
        'id' => 1,
        'nama' => 'item',
      ),
    ),
  ),
);
    }
}
