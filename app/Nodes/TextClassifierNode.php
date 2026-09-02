<?php

namespace App\Nodes;

/**
 * Text Classifier (setara n8n): klasifikasikan teks ke kategori yang
 * didefinisikan user. Hasil ditambahkan ke item sebagai field `classification`
 * + `confidence_reason`, sehingga bisa dirutekan dengan node IF/Switch.
 */
class TextClassifierNode extends AbstractNode
{
    public function getType(): string
    {
        return 'text_classifier';
    }

    public function getName(): string
    {
        return 'Text Classifier';
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
        return 'tags';
    }

    public function getDescription(): string
    {
        return 'Klasifikasikan teks ke salah satu kategori yang Anda definisikan. Rutekan hasilnya dengan node IF/Switch.';
    }

    public function getParameters(): array
    {
        return [
            ['key' => 'credential', 'label' => 'Credential AI', 'type' => 'credentials', 'credentialType' => 'openai'],
            ['key' => 'model', 'label' => 'Model', 'type' => 'text', 'default' => 'openai/gpt-4o-mini'],
            ['key' => 'text_field', 'label' => 'Field Teks', 'type' => 'text', 'default' => 'text', 'placeholder' => 'text'],
            ['key' => 'categories', 'label' => 'Kategori (JSON array)', 'type' => 'json', 'required' => true,
             'placeholder' => '[{"name":"bug","description":"laporan error"},{"name":"question","description":"pertanyaan"}]'],
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
            'title'  => 'Contoh: klasifikasi tiket support',
            'input'  => ['text' => 'Aplikasi error saat klik tombol simpan'],
            'params' => [
                'model'      => 'openai/gpt-4o-mini',
                'text_field' => 'text',
                'categories' => [
                    ['name' => 'bug', 'description' => 'laporan error/masalah teknis'],
                    ['name' => 'question', 'description' => 'pertanyaan umum'],
                    ['name' => 'compliment', 'description' => 'pujian/ucapan terima kasih'],
                ],
                'temperature' => 0,
            ],
        ]];
    }

    public function getExampleOutput(): array
    {
        return ['main' => [['json' => [
            'text'       => 'Aplikasi error saat klik tombol simpan',
            'classification'   => 'bug',
            'classification_reason' => 'Menyebutkan "error" saat menggunakan fitur',
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

        $catsRaw = (string) ($params['categories'] ?? '');
        $cats = json_decode($catsRaw !== '' ? $catsRaw : '[]', true);
        if (! is_array($cats) || $cats === []) {
            throw new \Exception('Kategori wajib diisi sebagai JSON array.');
        }
        $names = [];
        $descs = [];
        foreach ($cats as $c) {
            $n = trim((string) ($c['name'] ?? ''));
            if ($n === '') {
                continue;
            }
            $names[] = $n;
            $descs[] = '- ' . $n . ': ' . ($c['description'] ?? '');
        }
        if ($names === []) {
            throw new \Exception('Tidak ada kategori valid.');
        }

        $textField = (string) ($params['text_field'] ?? 'text');
        $items = [];
        foreach ($inputItems as $item) {
            $data = is_array($item) && array_key_exists('json', $item) ? $item['json'] : $item;
            $text = is_array($data) ? (string) ($data[$textField] ?? '') : (string) $data;

            $system = 'Kamu mesin klasifikasi teks. Pilih SATU kategori paling sesuai. '
                . "Balas HANYA JSON: {\"classification\": \"<nama>\", \"reason\": \"<singkat>\"}. "
                . "Kategori yang tersedia:\n" . implode("\n", $descs);
            $user = mb_substr($text, 0, 8000);

            $body = $this->llmPostJson($baseUrl . '/chat/completions', [
                'model'          => (string) ($params['model'] ?? 'openai/gpt-4o-mini'),
                'messages'       => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'temperature'    => (float) ($params['temperature'] ?? 0),
                'max_tokens'     => 200,
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

            $parsed = json_decode((string) $resp['choices'][0]['message']['content'], true);
            $class = is_array($parsed) ? strtolower(trim((string) ($parsed['classification'] ?? ''))) : '';
            $reason = is_array($parsed) ? (string) ($parsed['reason'] ?? '') : '';

            // Normalisasi: harus salah satu kategori, fallback kategori pertama.
            $matched = null;
            foreach ($names as $n) {
                if (strtolower($n) === $class) {
                    $matched = $n;
                    break;
                }
            }
            if ($matched === null) {
                $matched = $names[0];
            }

            if (is_array($item) && array_key_exists('json', $item)) {
                $out = $item;
                $out['json']['classification'] = $matched;
                $out['json']['classification_reason'] = $reason;
            } else {
                $out = is_array($item) ? $item : [];
                $out['classification'] = $matched;
                $out['classification_reason'] = $reason;
                $out = ['json' => $out];
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

