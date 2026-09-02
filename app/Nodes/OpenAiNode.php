<?php

namespace App\Nodes;

class OpenAiNode extends AbstractNode
{
    use LlmCallerTrait;

    protected function defaultBaseUrl(): string
    {
        return 'https://api.openai.com/v1';
    }

    public function getType(): string
    {
        return 'openai';
    }

    public function getName(): string
    {
        return 'OpenAI';
    }

    public function getCategory(): string
    {
        return 'AI';
    }

    public function getColor(): string
    {
        return '#10a37f';
    }

    public function getIcon(): string
    {
        return 'openai';
    }

    public function getDescription(): string
    {
        return 'Panggil model OpenAI (chat completions).';
    }

    public function getExamples(): array
    {
        return [
            [
                'title'  => 'Contoh: klasifikasi sentimen',
                'input'  => ['review' => 'Produknya bagus sekali, pengiriman cepat'],
                'params' => [
                    'model'          => 'gpt-4o-mini',
                    'system'         => 'Kamu adalah asisten analisis sentimen. Jawab dengan: positif / netral / negatif.',
                    'prompt'         => 'Klasifikasikan review ini: {{$json.review}}',
                    'temperature'    => 0.2,
                    'max_tokens'     => 100,
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
                    'content'       => 'positif',
                    'parsed'        => null,
                    'model'         => 'gpt-4o-mini',
                    'usage'         => ['prompt_tokens' => 25, 'completion_tokens' => 3, 'total_tokens' => 28],
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
                'label'       => 'Credential OpenAI',
                'type'        => 'credentials',
                'credentialType' => 'openai',
                'required'    => true,
            ],
            [
                'key'      => 'model',
                'label'    => 'Model',
                'type'     => 'text',
                'required' => true,
                'default'  => 'gpt-4o-mini',
                'placeholder' => 'gpt-4o-mini',
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
            throw new \Exception('Pilih credential OpenAI pada node ini.');
        }

        return ['main' => $this->callLlm($credential, $params, $inputItems, $context)];
    }
}
