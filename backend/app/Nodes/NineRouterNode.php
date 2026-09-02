<?php

namespace App\Nodes;

class NineRouterNode extends AbstractNode
{
    use LlmCallerTrait;

    protected function defaultBaseUrl(): string
    {
        return 'https://api.9router.com/v1';
    }

    public function getType(): string
    {
        return '9router';
    }

    public function getName(): string
    {
        return '9Router';
    }

    public function getCategory(): string
    {
        return 'AI';
    }

    public function getColor(): string
    {
        return '#26b17e';
    }

    public function getIcon(): string
    {
        return 'sparkles';
    }

    public function getDescription(): string
    {
        return 'Panggil model AI lewat 9Router (kompatibel OpenAI).';
    }

    public function getExamples(): array
    {
        return [
            [
                'title'  => 'Contoh: ringkas data item',
                'input'  => ['topic' => 'Rekomendasi laptop untuk mahasiswa', 'harga' => '8 juta'],
                'params' => [
                    'model'          => 'openai/gpt-4o-mini',
                    'system'         => 'Kamu adalah penulis konten SEO berbahasa Indonesia.',
                    'prompt'         => 'Tulis 3 poin rekomendasi tentang: {{$json.topic}} dengan anggaran {{$json.harga}}',
                    'temperature'    => 0.7,
                    'max_tokens'     => 500,
                    'response_format' => 'text',
                ],
            ],
        ];
    }

    public function getExampleOutput(): array
    {
        return [
            'main' => [[
                'json' => [
                    'content'       => '1. Prosesor Intel i5 generasi terbaru untuk multitasking... 2. RAM 16 GB... 3. Layar IPS 14 inci...',
                    'parsed'        => null,
                    'model'         => 'openai/gpt-4o-mini',
                    'usage'         => ['prompt_tokens' => 30, 'completion_tokens' => 90, 'total_tokens' => 120],
                    'finish_reason' => 'stop',
                ],
            ]],
        ];
    }

    public function getParameters(): array
    {
        return [
            [
                'key'         => 'credential',
                'label'       => 'Credential 9Router',
                'type'        => 'credentials',
                'credentialType' => '9router',
                'required'    => true,
            ],
            [
                'key'      => 'model',
                'label'    => 'Model',
                'type'     => 'text',
                'required' => true,
                'default'  => 'openai/gpt-4o-mini',
                'placeholder' => 'openai/gpt-4o-mini',
            ],
            [
                'key'      => 'system',
                'label'    => 'System Prompt',
                'type'     => 'textarea',
            ],
            [
                'key'      => 'prompt',
                'label'    => 'User Prompt',
                'type'     => 'textarea',
                'required' => true,
                'placeholder' => 'Analisis data ini: {{$json}}',
            ],
            [
                'key'     => 'temperature',
                'label'   => 'Temperature',
                'type'    => 'number',
                'default' => 0.7,
            ],
            [
                'key'     => 'max_tokens',
                'label'   => 'Max Tokens',
                'type'    => 'number',
                'default' => 2000,
            ],
            [
                'key'     => 'response_format',
                'label'   => 'Response Format',
                'type'    => 'select',
                'options' => ['text', 'json_object'],
                'default' => 'text',
            ],
            [
                'key'     => 'retryCount',
                'label'   => 'Retry Saat Gagal',
                'type'    => 'number',
                'default' => 0,
                'description' => 'Berapa kali ulang bila panggilan AI gagal.',
            ],

                ['key'     => 'retryBackoffMs',
                'label'   => 'Delay Dasar Retry (ms)',
                'type'    => 'number',
                'default' => 500,
                'description' => 'Delay awal antar percobaan, naik eksponensial per percobaan.',
            ],
            [
                'key'     => 'retryMaxDelayMs',
                'label'   => 'Delay Maks Retry (ms)',
                'type'    => 'number',
                'default' => 30000,
                'description' => 'Batas atas delay antar percobaan.',
            ],
        ];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $credential = $context->parameters['credential'] ?? null;
        if (! is_array($credential)) {
            $credential = $params['credential'] ?? null;
        }
        if (! is_array($credential)) {
            throw new \Exception('Pilih credential 9Router pada node ini.');
        }

        return ['main' => $this->callLlm($credential, $params, $inputItems, $context)];
    }
}
