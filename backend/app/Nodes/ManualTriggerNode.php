<?php

namespace App\Nodes;

class ManualTriggerNode extends AbstractNode
{
    public function getType(): string
    {
        return 'manual_trigger';
    }

    public function getName(): string
    {
        return 'Manual Trigger';
    }

    public function getCategory(): string
    {
        return 'Trigger';
    }

    public function getColor(): string
    {
        return '#ff6d5a';
    }

    public function getIcon(): string
    {
        return 'play';
    }

    public function getDescription(): string
    {
        return 'Jalankan workflow secara manual dari dashboard atau API.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'         => 'payload',
                'label'       => 'Payload (JSON)',
                'type'        => 'json',
                'default'     => '{}',
                'placeholder' => '{"key": "value"}',
                'description' => 'Data awal yang diteruskan ke node berikutnya.',
            ],
        ];
    }

    public function isTrigger(): bool
    {
        return true;
    }

    public function getTriggerKinds(): array
    {
        return ['manual', 'subworkflow'];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        if ($inputItems !== []) {
            return ['main' => $inputItems];
        }

        $payload = $params['payload'] ?? '{}';
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $payload = $decoded;
            } else {
                $payload = [];
            }
        }

        if (! is_array($payload)) {
            $payload = ['value' => $payload];
        }

        return ['main' => [$payload]];
    }

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: mulai dengan data manual',
    'input' => 
    array (
    ),
    'params' => 
    array (
      'payload' => '{"pesan":"Halo dunia"}',
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
        'pesan' => 'Halo dunia',
      ),
    ),
  ),
);
    }
}
