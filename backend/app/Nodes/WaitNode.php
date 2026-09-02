<?php

namespace App\Nodes;

class WaitNode extends AbstractNode
{
    public function getType(): string
    {
        return 'wait';
    }

    public function getName(): string
    {
        return 'Wait';
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
        return 'wait';
    }

    public function getDescription(): string
    {
        return 'Jeda eksekusi selama beberapa detik (delay).';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'     => 'delaySec',
                'label'   => 'Jeda (detik)',
                'type'    => 'number',
                'default' => 1,
                'required' => true,
            ],
        ];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $delay = max(0, (float) ($params['delaySec'] ?? 1));
        if ($delay > 0) {
            usleep((int) round($delay * 1000000));
        }

        return ['main' => $inputItems];
    }

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: tunggu 30 detik antar langkah',
    'input' => 
    array (
    ),
    'params' => 
    array (
      'delaySec' => 30,
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
        'waited' => 30,
      ),
    ),
  ),
);
    }
}
