<?php

namespace App\Nodes;

class FilterNode extends AbstractNode
{
    use ConditionEvaluator;

    public function getType(): string
    {
        return 'filter';
    }

    public function getName(): string
    {
        return 'Filter';
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
        return 'filter';
    }

    public function getDescription(): string
    {
        return 'Hanya teruskan item yang memenuhi kondisi.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'         => 'matchType',
                'label'       => 'Syarat',
                'type'        => 'select',
                'options'     => ['all', 'any'],
                'default'     => 'all',
                'description' => 'all = semua kondisi harus terpenuhi, any = cukup salah satu.',
            ],
            [
                'key'         => 'conditions',
                'label'       => 'Conditions',
                'type'        => 'conditions',
                'default'     => [['left' => '', 'operator' => '==', 'right' => '']],
            ],
        ];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $conditions = $params['conditions'] ?? [];
        if (is_string($conditions)) {
            $conditions = json_decode($conditions, true) ?: [];
        }

        $matchType = $params['matchType'] ?? 'all';
        $kept      = [];

        foreach ($inputItems as $item) {
            $results = $this->evaluateConditions((array) $conditions, $item, $context);

            $ok = $matchType === 'any'
                ? in_array(true, $results, true)
                : ($results !== [] && ! in_array(false, $results, true));

            if ($ok) {
                $kept[] = $item;
            }
        }

        return ['main' => $kept];
    }

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: loloskan stok lebih dari 0',
    'input' => 
    array (
      'produk' => 'Laptop',
      'stok' => 5,
    ),
    'params' => 
    array (
      'conditions' => '[{"left":"{{$json.stok}}","operator":">","right":0}]',
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
        'stok' => 5,
      ),
    ),
  ),
);
    }
}
