<?php

namespace App\Nodes;

class RemoveDuplicatesNode extends AbstractNode
{
    public function getType(): string
    {
        return 'remove_duplicates';
    }

    public function getName(): string
    {
        return 'Remove Duplicates';
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
        return 'dedupe';
    }

    public function getDescription(): string
    {
        return 'Hapus item duplikat berdasarkan satu field.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'         => 'keyField',
                'label'       => 'Field Unik',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => 'email',
                'description' => 'Item dengan nilai field yang sama hanya dipertahankan satu (pertama).',
            ],
        ];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $keyField = $params['keyField'] ?? '';
        $seen     = [];
        $items    = [];

        foreach ($inputItems as $item) {
            if ($keyField !== '') {
                $value = $this->resolveField((string) $keyField, $item, $context);
                $sign  = is_array($value) || is_object($value) ? json_encode($value) : (string) $value;

                if (isset($seen[$sign])) {
                    continue;
                }
                $seen[$sign] = true;
            }

            $items[] = $item;
        }

        return ['main' => $items];
    }

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: buang email ganda',
    'input' => 
    array (
      'email' => 'riski@example.com',
    ),
    'params' => 
    array (
      'keyField' => 'email',
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
      ),
    ),
  ),
);
    }
}
