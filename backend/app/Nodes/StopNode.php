<?php

namespace App\Nodes;

/**
 * Hentikan eksekusi workflow. Node setelahnya tidak dijalankan.
 * Eksekusi ditandai 'stopped' (sukses, tapi berhenti lebih awal).
 */
class StopNode extends AbstractNode
{
    public function getType(): string
    {
        return 'stop';
    }

    public function getName(): string
    {
        return 'Stop';
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
        return 'stop';
    }

    public function getDescription(): string
    {
        return 'Hentikan alur workflow di titik ini.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'      => 'message',
                'label'    => 'Pesan (opsional)',
                'type'     => 'text',
                'placeholder' => 'Alur dihentikan sesuai aturan.',
                'description' => 'Pesan ini tersimpan pada execution.',
            ],
        ];
    }

    public function getOutputs(): array
    {
        return ['main'];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $seed    = $inputItems[0] ?? [];
        $message = (string) $context->resolve($params['message'] ?? '', is_array($seed) ? $seed : []);

        throw new StopWorkflowException($message !== '' ? $message : 'Workflow dihentikan oleh node Stop.');
    }

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: hentikan workflow bila data tidak valid',
    'input' => 
    array (
      'email' => '',
    ),
    'params' => 
    array (
      'message' => 'Workflow dihentikan: email kosong',
    ),
  ),
);
    }

    public function getExampleOutput(): array
    {
        return array (
  'main' => 
  array (
  ),
);
    }
}
