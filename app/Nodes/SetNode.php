<?php

namespace App\Nodes;

class SetNode extends AbstractNode
{
    public function getType(): string
    {
        return 'set';
    }

    public function getName(): string
    {
        return 'Set';
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
        return 'set';
    }

    public function getDescription(): string
    {
        return 'Atur / timpa / hapus field pada data (Edit Fields).';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'         => 'assignments',
                'label'       => 'Assignments (JSON)',
                'type'        => 'json',
                'default'     => '[{"field": "name", "value": "{{$json.name}}"} ]',
                'description' => 'Array: [{"field": "namaField", "value": "{{$json.sumber}}"}]',
            ],
            [
                'key'         => 'removeFields',
                'label'       => 'Hapus Field (JSON)',
                'type'        => 'json',
                'default'     => '[]',
                'description' => 'Array nama field yang dihapus, contoh: ["internal", "raw"]',
            ],
        ];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $assignments = $this->asArray($params['assignments'] ?? []);
        $removeFields = $this->asArray($params['removeFields'] ?? []);

        $items = [];
        foreach ($inputItems as $item) {
            $wrapped = array_key_exists('json', $item) && is_array($item['json']);
            $data    = $wrapped ? $item['json'] : $item;

            foreach ($assignments as $assign) {
                if (! is_array($assign) || empty($assign['field'])) {
                    continue;
                }

                $value = $context->resolve($assign['value'] ?? null, $item);
                $data[$assign['field']] = $value;
            }

            foreach ($removeFields as $field) {
                if (is_array($data)) {
                    unset($data[$field]);
                }
            }

            $items[] = $wrapped ? ['json' => $data] : $data;
        }

        return ['main' => $items];
    }

    protected function asArray($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: tambah field status & sumber',
    'input' => 
    array (
      'email' => 'riski@example.com',
    ),
    'params' => 
    array (
      'assignments' => '[{"field":"status","value":"aktif"},{"field":"sumber","value":"webinar"}]',
      'removeFields' => '[]',
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
        'email' => 'riski@example.com',
        'status' => 'aktif',
        'sumber' => 'webinar',
      ),
    ),
  ),
);
    }
}
