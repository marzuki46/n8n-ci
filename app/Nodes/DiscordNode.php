<?php

namespace App\Nodes;

class DiscordNode extends AbstractNode
{
    public function getType(): string
    {
        return 'discord';
    }

    public function getName(): string
    {
        return 'Discord';
    }

    public function getCategory(): string
    {
        return 'Apps';
    }

    public function getColor(): string
    {
        return '#3aa0e0';
    }

    public function getIcon(): string
    {
        return 'discord';
    }

    public function getDescription(): string
    {
        return 'Kirim pesan ke Discord via Webhook.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'            => 'credential',
                'label'          => 'Credential Discord',
                'type'           => 'credentials',
                'credentialType' => 'discord',
                'description'    => 'Pakai webhook URL tersimpan.',
            ],
            [
                'key'         => 'webhookUrl',
                'label'       => 'Webhook URL (opsional jika credential dipilih)',
                'type'        => 'text',
                'placeholder' => 'https://discord.com/api/webhooks/...',
            ],
            [
                'key'      => 'content',
                'label'    => 'Pesan',
                'type'     => 'textarea',
                'required' => true,
            ],
            [
                'key'         => 'username',
                'label'       => 'Username (opsional)',
                'type'        => 'text',
                'placeholder' => 'FlowForge Bot',
            ],
        ];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $cred  = $context->parameters['credential'] ?? [];
        $items = [];
        foreach ($inputItems as $item) {
            $url      = (string) $context->resolve($params['webhookUrl'] ?? '', $item);
            $content  = (string) $context->resolve($params['content'] ?? '', $item);
            $username = (string) $context->resolve($params['username'] ?? '', $item);

            if ($url === '' && ! empty($cred['webhook_url'])) {
                $url = (string) $cred['webhook_url'];
            }

            if ($url === '') {
                throw new \Exception('Discord: webhook URL (atau credential) wajib diisi.');
            }

            $payload = ['content' => $content];
            if ($username !== '') {
                $payload['username'] = $username;
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS     => json_encode($payload),
            ]);

            $responseBody = curl_exec($ch);
            $error        = curl_error($ch);
            $httpCode     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($error || $httpCode >= 400) {
                throw new \Exception('Discord error (' . $httpCode . '): ' . ($error ?: $responseBody));
            }

            $items[] = [
                'json'   => ['ok' => empty($responseBody), 'response' => (string) $responseBody],
                'status' => $httpCode,
                'error'  => null,
            ];
        }

        return ['main' => $items];
    }

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: notifikasi order ke channel Discord',
    'input' => 
    array (
      'orderId' => 999,
      'item' => 'Laptop Pro',
      'total' => 'Rp13.500.000',
    ),
    'params' => 
    array (
      'webhookUrl' => 'https://discord.com/api/webhooks/123456/abc-def',
      'content' => '📦 **Order Baru** #{{$json.orderId}}
Item: {{$json.item}}
Total: {{$json.total}}',
      'username' => 'Bot Order',
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
        'ok' => true,
        'status' => 'sent',
      ),
    ),
  ),
);
    }
}
