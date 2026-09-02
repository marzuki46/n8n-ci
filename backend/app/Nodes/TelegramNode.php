<?php

namespace App\Nodes;

class TelegramNode extends AbstractNode
{
    public function getType(): string
    {
        return 'telegram';
    }

    public function getName(): string
    {
        return 'Telegram';
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
        return 'telegram';
    }

    public function getDescription(): string
    {
        return 'Kirim pesan lewat Telegram Bot API.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'            => 'credential',
                'label'          => 'Credential Telegram',
                'type'           => 'credentials',
                'credentialType' => 'telegram',
                'description'    => 'Pakai credential bot token tersimpan.',
            ],
            [
                'key'         => 'botToken',
                'label'       => 'Bot Token (opsional jika credential dipilih)',
                'type'        => 'password',
                'placeholder' => '123456:ABC-DEF...',
            ],
            [
                'key'         => 'chatId',
                'label'       => 'Chat ID',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => '-1001234567890 atau 123456789',
            ],
            [
                'key'         => 'text',
                'label'       => 'Pesan',
                'type'        => 'textarea',
                'required'    => true,
                'placeholder' => 'Halo {{$json.nama}}',
            ],
        ];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $cred  = $context->parameters['credential'] ?? [];
        $items = [];
        foreach ($inputItems as $item) {
            $token  = (string) $context->resolve($params['botToken'] ?? '', $item);
            $chatId = (string) $context->resolve($params['chatId'] ?? '', $item);
            $text   = (string) $context->resolve($params['text'] ?? '', $item);

            if ($token === '' && ! empty($cred['bot_token'])) {
                $token = (string) $cred['bot_token'];
            }

            if ($token === '' || $chatId === '') {
                throw new \Exception('Telegram: bot token (atau credential) dan chat ID wajib diisi.');
            }

            $payload = [
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'HTML',
            ];

            $items[] = $this->postJson('https://api.telegram.org/bot' . $token . '/sendMessage', $payload);
        }

        return ['main' => $items];
    }

    protected function postJson(string $url, array $payload): array
    {
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
        $isError = $error || $httpCode >= 400 || (is_array($decoded) && empty($decoded['ok']));

        if ($isError) {
            $message = is_array($decoded) ? ($decoded['description'] ?? $responseBody) : $responseBody;
            throw new \Exception('Telegram error (' . $httpCode . '): ' . ($error ?: $message));
        }

        return [
            'json'   => $decoded ?: (string) $responseBody,
            'status' => $httpCode,
            'error'  => $error ?: null,
        ];
    }

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: kirim notifikasi order ke Telegram',
    'input' => 
    array (
      'orderId' => 999,
      'total' => 'Rp13.500.000',
    ),
    'params' => 
    array (
      'botToken' => '123456:ABC-DEF1234',
      'chatId' => '{{$json.chatId}}',
      'text' => '📦 Order baru #{{$json.orderId}}
Total: {{$json.total}}',
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
        'message_id' => 45678,
      ),
    ),
  ),
);
    }
}
