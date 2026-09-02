<?php

namespace App\Nodes;

/**
 * Respond to Webhook (setara n8n).
 * Dipasang setelah trigger webhook/form; hasilnya dikembalikan sebagai
 * respons HTTP ke pemanggil webhook (lihat WebhookController).
 */
class RespondToWebhookNode extends AbstractNode
{
    public function getType(): string
    {
        return 'respond_to_webhook';
    }

    public function getName(): string
    {
        return 'Respond to Webhook';
    }

    public function getCategory(): string
    {
        return 'Core';
    }

    public function getColor(): string
    {
        return '#4d9fff';
    }

    public function getIcon(): string
    {
        return 'reply';
    }

    public function getDescription(): string
    {
        return 'Kirim data sebagai respons HTTP ke pemanggil webhook. Taruh di akhir cabang yang ingin dibalas.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'   => 'mode',
                'label' => 'Isi Respons',
                'type'  => 'select',
                'options' => [
                    ['value' => 'last_input', 'label' => 'Data input terakhir'],
                    ['value' => 'custom', 'label' => 'Body kustom'],
                ],
                'default' => 'last_input',
            ],
            [
                'key'         => 'body',
                'label'       => 'Body Kustom',
                'type'        => 'textarea',
                'placeholder' => '{{{ "ok": true, "message": "Data diterima: {{$json.field}}" }}}',
            ],
            [
                'key'     => 'status_code',
                'label'   => 'Status Code',
                'type'    => 'number',
                'default' => 200,
            ],
        ];
    }

    public function getOutputs(): array
    {
        return [];
    }

    public function isTrigger(): bool
    {
        return false;
    }

    public function getExamples(): array
    {
        return [[
            'title'  => 'Contoh: balas konfirmasi ke pemanggil webhook',
            'input'  => ['orderId' => 999, 'status' => 'diproses'],
            'params' => [
                'mode'         => 'custom',
                'body'         => '{"ok": true, "message": "Order {{$json.orderId}} diterima"}',
                'status_code'  => 200,
            ],
        ]];
    }

    public function getExampleOutput(): array
    {
        return [];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $mode = (string) ($params['mode'] ?? 'last_input');

        if ($mode === 'custom') {
            $resolved = $context->resolve((string) ($params['body'] ?? ''), $inputItems[0] ?? []);
            // Body kustom boleh berupa teks mentah / JSON.
            $decoded = json_decode($resolved, true);
            $payload = json_last_error() === JSON_ERROR_NONE ? $decoded : $resolved;
        } else {
            $item    = end($inputItems);
            $payload = is_array($item) && array_key_exists('json', $item)
                ? $item['json']
                : ($item ?: null);
        }

        // Dikonsumsi WebhookController via outputs engine.
        return ['main' => [['json' => [
            '__webhook_response__' => true,
            'status_code'          => (int) ($params['status_code'] ?? 200),
            'body'                 => $payload,
        ]]]];
    }
}
