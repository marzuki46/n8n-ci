<?php

namespace App\Nodes;

class MergeNode extends AbstractNode
{
    public function getType(): string
    {
        return 'merge';
    }

    public function getName(): string
    {
        return 'Merge';
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
        return 'merge';
    }

    public function getDescription(): string
    {
        return 'Gabungkan data dari beberapa cabang.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'     => 'mode',
                'label'   => 'Mode',
                'type'    => 'select',
                'options' => [
                    ['value' => 'append', 'label' => 'Append (gabung semua item)'],
                    ['value' => 'combine', 'label' => 'Combine (gabung jadi satu objek)'],
                ],
                'default' => 'append',
            ],
        ];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $mode = $params['mode'] ?? 'append';

        if ($mode === 'combine') {
            $merged = [];
            foreach ($inputItems as $item) {
                $data = $this->jsonData($item);
                foreach ($data as $k => $v) {
                    $merged[$k] = $v;
                }
            }

            return ['main' => [$merged]];
        }

        return ['main' => $inputItems];
    }

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: gabungkan hasil dua cabang',
    'input' => 
    array (
    ),
    'params' => 
    array (
      'mode' => 'combine',
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
        'merged' => true,
      ),
    ),
  ),
);
    }
}
