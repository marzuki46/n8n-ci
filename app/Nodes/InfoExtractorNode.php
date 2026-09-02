<?php

namespace App\Nodes;

/**
 * Information Extractor (setara n8n): ekstrak data terstruktur dari teks
 * sesuai schema JSON yang didefinisikan user (LLM + response_format json_object).
 */
class InfoExtractorNode extends AbstractNode
{
    public function getType(): string
    {
        return 'info_extractor';
    }

    public function getName(): string
    {
        return 'Information Extractor';
    }

    public function getCategory(): string
    {
        return 'AI';
    }

    public function getColor(): string
    {
        return '#a06bff';
    }

    public function getIcon(): string
    {
        return 'braces';
    }

    public function getDescription(): string
    {
        return 'Ekstrak data terstruktur dari teks bebas sesuai schema JSON. Hasil tersedia di field `extracted`.';
    }

    public function getParameters(): array
    {
        return [
            ['key' => 'credential', 'label' => 'Credential AI', 'type' => 'credentials', 'credentialType' => 'openai'],
            ['key' => 'model', 'label' => 'Model', 'type' => 'text', 'default' => 'openai/gpt-4o-mini'],
            ['key' => 'text_field', 'label' => 'Field Teks', 'type' => 'text', 'default' => 'text'],
            ['key' => 'schema', 'label' => 'Schema Output (JSON)', 'type' => 'json', 'required' => true,
             'placeholder' => '{"nama": "string", "email": "string", "produk": ["string"]}'],
            ['key' => 'extra_instructions', 'label' => 'Instruksi Tambahan', 'type' => 'textarea'],
            ['key' => 'temperature', 'label' => 'Temperature', 'type' => 'number', 'default' => 0],
        ];
    }

    public function getOutputs(): array
    {
        return ['main'];
    }

    public function isTrigger(): bool
    {
        return false;
    }

    public function getExamples(): array
    {
        return [[
            'title'  => 'Contoh: ekstrak kontak dari email masuk',
            'input'  => ['text' => 'Halo, saya Budi (budi@mail.com), tertarik paket website toko online.'],
            'params' => [
                'model'      => 'openai/gpt-4o-mini',
                'text_field' => 'text',
                'schema'     => ['nama' => 'string', 'email' => 'string', 'minat' => 'string'],
                'temperature' => 0,
            ],
        ]];
    }

    public function getExampleOutput(): array
    {
        return ['main' => [['json' => [
            'text'      => 'Halo, saya Budi (budi@mail.com)...',
            'extracted' => [
                'nama'  => 'Budi',
                'email' => 'budi@mail.com',
                'minat' => 'paket website toko online',
            ],
        ]]]];
    }

    use AiBudgetGuardTrait;

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $this->guardAiBudget($context);
        $credential = $context->parameters['credential'] ?? null;
        if (! is_array($credential) || empty($credential['api_key'])) {
            throw new \Exception('Pilih credential AI pada node ini.');
        }
        $baseUrl = rtrim($credential['base_url'] ?? 'https://api.9router.com', '/');

        $schemaRaw = (string) ($params['schema'] ?? '');
        $schema = json_decode($schemaRaw !== '' ? $schemaRaw : '{}', true);
        if (! is_array($schema) || $schema === []) {
            throw new \Exception('Schema wajib diisi sebagai JSON object.');
        }

        $textField = (string) ($params['text_field'] ?? 'text');
        $items = [];
        foreach ($inputItems as $item) {
            $data = is_array($item) && array_key_exists('json', $item) ? $item['json'] : $item;
            $text = is_array($data) ? (string) ($data[$textField] ?? '') : (string) $data;

            $system = 'Kamu mesin ekstraksi data. Ekstrak informasi sesuai schema JSON berikut. '
                . 'Jika data tidak ditemukan, isi null. Balas HANYA JSON valid tanpa penjelasan.\n'
                . 'Schema: ' . json_encode($schema, JSON_UNESCAPED_UNICODE);
            if (! empty($params['extra_instructions'])) {
                $system .= "\nInstruksi tambahan: " . (string) $params['extra_instructions'];
            }

            $body = $this->llmPostJson($baseUrl . '/chat/completions', [
                'model'          => (string) ($params['model'] ?? 'openai/gpt-4o-mini'),
                'messages'       => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => mb_substr($text, 0, 12000)],
                ],
                'temperature'    => (float) ($params['temperature'] ?? 0),
                'max_tokens'     => 1500,
                'response_format' => ['type' => 'json_object'],
            ], (string) $credential['api_key']);

            $resp = json_decode((string) $body, true);
            if (! isset($resp['choices'][0]['message']['content'])) {
                throw new \Exception('AI API error: ' . substr((string) $body, 0, 200));
            }

            (new \App\Services\AiUsageService())->log(
                isset($context->workflow['id']) ? (int) $context->workflow['id'] : null,
                null,
                $resp['model'] ?? ($params['model'] ?? null),
                $resp['usage'] ?? null
            );

            $extracted = json_decode((string) $resp['choices'][0]['message']['content'], true);
            if (! is_array($extracted)) {
                $extracted = [];
            }

            if (is_array($item) && array_key_exists('json', $item)) {
                $out = $item;
                $out['json']['extracted'] = $extracted;
            } else {
                $base = is_array($item) ? $item : [];
                $base['extracted'] = $extracted;
                $out = ['json' => $base];
            }
            $items[] = $out;
        }

        return ['main' => $items];
    }

    protected function llmPostJson(string $url, array $payload, string $apiKey): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($err || $code >= 400) {
            throw new \RuntimeException('AI API error (' . $code . '): ' . $err);
        }

        return (string) $resp;
    }
}

