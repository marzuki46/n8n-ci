<?php

namespace App\Nodes;

/**
 * Pemanggilan LLM lewat API kompatibel OpenAI (/v1/chat/completions).
 * Dipakai node 9Router, OpenAI, dan No-AI-Slop.
 */
trait LlmCallerTrait
{
    protected function defaultBaseUrl(): string
    {
        return 'https://api.9router.com';
    }

    /**
     * Panggil chat completion untuk tiap item.
     *
     * @return array item keluaran dengan key 'json' berisi content + parsed
     */
    protected function callLlm(array $credential, array $params, array $inputItems, WorkflowContext $context): array
    {
        // Guardrail kuota AI (block/warn) — berlaku untuk semua node LLM.
        if (method_exists($this, 'guardAiBudget')) {
            $this->guardAiBudget($context);
        } else {
            (new \App\Services\AiUsageService())->guard(
                isset($context->workflow['workspace_id']) ? (int) $context->workflow['workspace_id'] : 0
            );
        }

        $apiKey = $credential['api_key'] ?? null;
        if (! $apiKey) {
            throw new \Exception('Credential AI tidak memiliki API Key.');
        }

        $baseUrl = rtrim($credential['base_url'] ?? $this->defaultBaseUrl(), '/');

        $items = [];
        foreach ($inputItems as $item) {
            $system = $context->resolve($params['system'] ?? '', $item);
            $prompt = $context->resolve($params['prompt'] ?? '', $item);

            $payload = [
                'model'       => $context->resolve($params['model'] ?? 'openai/gpt-4o-mini', $item),
                'messages'    => [],
                'temperature' => (float) ($params['temperature'] ?? 0.7),
                'max_tokens'  => (int) ($params['max_tokens'] ?? 2000),
            ];

            if (! empty($system)) {
                $payload['messages'][] = ['role' => 'system', 'content' => (string) $system];
            }
            $payload['messages'][] = ['role' => 'user', 'content' => (string) $prompt];

            if (($params['response_format'] ?? 'text') === 'json_object') {
                $payload['response_format'] = ['type' => 'json_object'];
            }

            $responseBody = $this->httpPostJson($baseUrl . '/chat/completions', $payload, $apiKey);

            $response = json_decode((string) $responseBody, true);

            if (! isset($response['choices'][0]['message']['content'])) {
                throw new \Exception('AI API error: ' . ($response['error']['message'] ?? $responseBody));
            }

            // Log pemakaian token (tabel ai_usage, best-effort).
            (new \App\Services\AiUsageService())->log(
                isset($context->workflow['id']) ? (int) $context->workflow['id'] : null,
                null,
                $response['model'] ?? ($payload['model'] ?? null),
                $response['usage'] ?? null
            );

            $content = $response['choices'][0]['message']['content'];

            // Output mewarisi field input (mis. topic) + hasil AI.
            // Input bisa terbungkus {"json": {...}} atau data polos.
            $base = [];
            if (is_array($item) && array_key_exists('json', $item) && is_array($item['json'])) {
                $base = $item['json'];
            } elseif (is_array($item)) {
                $base = $item;
            }
            $items[] = [
                'json' => array_merge($base, [
                    'content'       => $content,
                    'parsed'        => $this->tryParseJson($content),
                    'model'         => $response['model'] ?? null,
                    'usage'         => $response['usage'] ?? null,
                    'finish_reason' => $response['choices'][0]['finish_reason'] ?? null,
                ]),
            ];
        }

        return $items;
    }

    protected function httpPostJson(string $url, array $payload, string $apiKey): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $responseBody = curl_exec($ch);
        $error        = curl_error($ch);
        $httpCode     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($error || $httpCode >= 400) {
            throw new \Exception('AI API error (' . $httpCode . '): ' . $error);
        }

        return (string) $responseBody;
    }

    protected function tryParseJson(string $content)
    {
        $decoded = json_decode($content, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}
