<?php

namespace App\Nodes;

class WebhookNode extends AbstractNode
{
    public function getType(): string
    {
        return 'webhook';
    }

    public function getName(): string
    {
        return 'Webhook';
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
        return 'webhook';
    }

    public function getDescription(): string
    {
        return 'Terima panggilan HTTP masuk lalu lanjutkan workflow.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'         => 'path',
                'label'       => 'Path',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => 'my-webhook',
                'description' => 'Path unik webhook. URL lengkap: {baseURL}/webhook/{path}',
            ],
            [
                'key'      => 'method',
                'label'    => 'Method',
                'type'     => 'select',
                'options'  => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
                'default'  => 'POST',
            ],
            [
                'key'         => 'auth_token',
                'label'       => 'Auth Token (opsional)',
                'type'        => 'password',
                'placeholder' => 'Isi jika ingin melindungi webhook',
            ],
        ];
    }

    public function isTrigger(): bool
    {
        return true;
    }

    public function getTriggerKinds(): array
    {
        return ['manual', 'webhook'];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        // Data payload webhook disuntikkan via context->parameters saat dipanggil dari controller webhook
        $data = $context->parameters['webhookData'] ?? [];

        if (empty($data)) {
            $data = ['body' => [], 'query' => [], 'headers' => [], 'method' => 'GET'];
        }

        return ['main' => [$data]];
    }

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: terima data form dari luar',
    'input' => 
    array (
    ),
    'params' => 
    array (
      'path' => 'order-baru',
      'method' => 'POST',
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
        'body' => 
        array (
          'produk' => 'Laptop',
        ),
        'query' => 
        array (
        ),
      ),
    ),
  ),
);
    }
}
