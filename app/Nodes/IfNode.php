<?php

namespace App\Nodes;

class IfNode extends AbstractNode
{
    use ConditionEvaluator;

    public function getType(): string
    {
        return 'if';
    }

    public function getName(): string
    {
        return 'IF';
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
        return 'branch';
    }

    public function getDescription(): string
    {
        return 'Cabangkan alur berdasarkan kondisi. Output true / false.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'         => 'conditions',
                'label'       => 'Conditions',
                'type'        => 'conditions',
                'default'     => [['left' => '', 'operator' => '==', 'right' => '']],
                'description' => 'Semua kondisi harus terpenuhi (AND).',
            ],
        ];
    }

    public function getOutputs(): array
    {
        return ['true', 'false'];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $conditions = $params['conditions'] ?? [];
        if (is_string($conditions)) {
            $conditions = json_decode($conditions, true) ?: [];
        }

        $trueItems  = [];
        $falseItems = [];

        foreach ($inputItems as $item) {
            $resolved = $this->evaluateConditions((array) $conditions, $item, $context);

            if ($resolved && ! in_array(false, $resolved, true)) {
                $trueItems[] = $item;
            } else {
                $falseItems[] = $item;
            }
        }

        return ['true' => $trueItems, 'false' => $falseItems];
    }

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: cabang pelanggan VIP vs reguler',
    'input' => 
    array (
      'nama' => 'Riski',
      'totalBelanja' => 5000000,
    ),
    'params' => 
    array (
      'conditions' => '[{"left":"{{$json.totalBelanja}}","operator":">=","right":1000000}]',
    ),
  ),
);
    }

    public function getExampleOutput(): array
    {
        return array (
  'true' => 
  array (
    0 => 
    array (
      'json' => 
      array (
        'nama' => 'Riski',
        'totalBelanja' => 5000000,
      ),
    ),
  ),
  'false' => 
  array (
  ),
);
    }
}
