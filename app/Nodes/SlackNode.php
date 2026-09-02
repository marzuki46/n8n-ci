<?php

namespace App\Nodes;

class SlackNode extends AbstractNode
{
    public function getType(): string
    {
        return 'slack';
    }

    public function getName(): string
    {
        return 'Slack';
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
        return 'slack';
    }

    public function getDescription(): string
    {
        return 'Kirim pesan ke Slack via Incoming Webhook.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'            => 'credential',
                'label'          => 'Credential Slack',
                'type'           => 'credentials',
                'credentialType' => 'slack',
                'description'    => 'Pakai webhook URL tersimpan.',
            ],
            [
                'key'         => 'webhookUrl',
                'label'       => 'Webhook URL (opsional jika credential dipilih)',
                'type'        => 'text',
                'placeholder' => 'https://hooks.slack.com/services/T.../B.../xxx',
            ],
            [
                'key'      => 'text',
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
            $text     = (string) $context->resolve($params['text'] ?? '', $item);
            $username = (string) $context->resolve($params['username'] ?? '', $item);

            if ($url === '' && ! empty($cred['webhook_url'])) {
                $url = (string) $cred['webhook_url'];
            }

            if ($url === '') {
                throw new \Exception('Slack: webhook URL (atau credential) wajib diisi.');
            }

            $payload = ['text' => $text];
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

            $decoded = json_decode((string) $responseBody, true);
            $ok      = $responseBody === 'ok' || empty($responseBody)
                || (is_array($decoded) && ($decoded['ok'] ?? false) === true);

            if ($error || $httpCode >= 400 || ! $ok) {
                throw new \Exception('Slack error (' . $httpCode . '): ' . ($error ?: $responseBody));
            }

            $items[] = [
                'json'   => ['ok' => $ok, 'response' => (string) $responseBody],
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
    'title' => 'Contoh: pengumuman deploy ke Slack',
    'input' => 
    array (
      'versi' => 'v1.2.0',
      'oleh' => 'Riski',
    ),
    'params' => 
    array (
      'webhookUrl' => 'https://hooks.slack.com/services/T000/B000/XXXX',
      'text' => '✅ Deploy *{{$json.versi}}* selesai oleh {{$json.oleh}}',
      'username' => 'deploy-bot',
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
      ),
    ),
  ),
);
    }
}
