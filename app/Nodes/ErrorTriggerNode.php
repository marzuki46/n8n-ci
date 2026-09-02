<?php

namespace App\Nodes;

/**
 * Trigger yang berjalan saat node lain gagal (error handling).
 * Tidak ikut berjalan saat workflow dimulai; hanya aktif bila ada error.
 */
class ErrorTriggerNode extends AbstractNode
{
    public function getType(): string
    {
        return 'error_trigger';
    }

    public function getName(): string
    {
        return 'Error Trigger';
    }

    public function getCategory(): string
    {
        return 'Trigger';
    }

    public function getColor(): string
    {
        return '#f05252';
    }

    public function getIcon(): string
    {
        return 'error';
    }

    public function getDescription(): string
    {
        return 'Berjalan saat node lain gagal. Berguna untuk notifikasi/catatan error.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'      => 'notify',
                'label'    => 'Sertakan Detail Error di Output',
                'type'     => 'boolean',
                'default'  => true,
            ],
        ];
    }

    public function isTrigger(): bool
    {
        return true;
    }

    public function getTriggerKinds(): array
    {
        return ['error'];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $errorInfo = $context->parameters['errorInfo'] ?? null;

        if (! is_array($errorInfo)) {
            $errorInfo = ['message' => 'Tidak ada informasi error.'];
        }

        if (($params['notify'] ?? true) === false) {
            $errorInfo = ['node_id' => $errorInfo['node_id'] ?? null];
        }

        return ['main' => [$errorInfo]];
    }

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: jalankan saat workflow gagal',
    'input' => 
    array (
    ),
    'params' => 
    array (
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
        'errorInfo' => 
        array (
          'message' => 'Contoh error',
          'node_name' => 'HTTP Request',
        ),
      ),
    ),
  ),
);
    }
}
