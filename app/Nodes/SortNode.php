<?php

namespace App\Nodes;

class SortNode extends AbstractNode
{
    public function getType(): string
    {
        return 'sort';
    }

    public function getName(): string
    {
        return 'Sort';
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
        return 'sort';
    }

    public function getDescription(): string
    {
        return 'Urutkan item berdasarkan satu field.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'         => 'sortBy',
                'label'       => 'Field',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => 'harga',
            ],
            [
                'key'     => 'order',
                'label'   => 'Urutan',
                'type'    => 'select',
                'options' => ['asc', 'desc'],
                'default' => 'asc',
            ],
            [
                'key'     => 'type',
                'label'   => 'Tipe',
                'type'    => 'select',
                'options' => ['auto', 'number', 'string'],
                'default' => 'auto',
            ],
        ];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $sortBy = $params['sortBy'] ?? '';
        $order  = $params['order'] ?? 'asc';
        $type   = $params['type'] ?? 'auto';

        $pairs = [];
        foreach ($inputItems as $i => $item) {
            $value = $this->resolveField((string) $sortBy, $item, $context);
            if ($type === 'number') {
                $value = is_numeric($value) ? $value + 0 : 0;
            } elseif ($type === 'string') {
                $value = (string) $value;
            }
            $pairs[] = ['item' => $item, 'value' => $value];
        }

        usort($pairs, function ($a, $b) use ($order) {
            $va = $a['value'];
            $vb = $b['value'];

            if (is_numeric($va) && is_numeric($vb)) {
                $cmp = ($va + 0) <=> ($vb + 0);
            } else {
                $cmp = strcmp((string) $va, (string) $vb);
            }

            return $order === 'desc' ? -$cmp : $cmp;
        });

        return ['main' => array_column($pairs, 'item')];
    }

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: urutkan produk termahal dulu',
    'input' => 
    array (
      'produk' => 'Laptop',
      'harga' => 15000000,
    ),
    'params' => 
    array (
      'sortBy' => 'harga',
      'order' => 'desc',
      'type' => 'number',
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
        'produk' => 'Laptop',
        'harga' => 15000000,
      ),
    ),
  ),
);
    }
}
