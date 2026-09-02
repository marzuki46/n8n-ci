<?php

namespace App\Nodes;

/**
 * AI Agent (setara n8n Tools Agent, versi ringkas).
 * Loop tool-calling OpenAI-compatible: LLM boleh memanggil tools yang
 * didefinisikan user (HTTP call atau sub-workflow) sampai jawaban final
 * atau batas iterasi. Mendukung memori percakapan per memory_key.
 */
class AiAgentNode extends AbstractNode
{
    public function getType(): string
    {
        return 'ai_agent';
    }

    public function getName(): string
    {
        return 'AI Agent';
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
        return 'bot';
    }

    public function getDescription(): string
    {
        return 'Agent AI otonom: bisa memanggil tools (HTTP atau workflow lain) berulang kali untuk menyelesaikan tugas sebelum menjawab.';
    }

    public function getParameters(): array
    {
        return [
            ['key' => 'credential', 'label' => 'Credential AI', 'type' => 'credentials', 'credentialType' => 'openai'],
            ['key' => 'model', 'label' => 'Model', 'type' => 'text', 'default' => 'openai/gpt-4o-mini'],
            ['key' => 'system', 'label' => 'System Prompt', 'type' => 'textarea', 'placeholder' => 'Kamu asisten yang...'],
            ['key' => 'prompt', 'label' => 'Prompt / Tugas', 'type' => 'textarea', 'required' => true, 'placeholder' => 'Cek status {{$json.url}} lalu rangkum'],
            ['key' => 'tools', 'label' => 'Tools (JSON)', 'type' => 'json', 'placeholder' => '[{"name":"get_status","description":"Cek status URL","type":"http","method":"GET","url":"https://..."}]'],
            ['key' => 'memory_key', 'label' => 'Memory Key (opsional)', 'type' => 'text', 'placeholder' => 'chat-user-123'],
            ['key' => 'max_iterations', 'label' => 'Maks Iterasi Tool', 'type' => 'number', 'default' => 5],
            ['key' => 'temperature', 'label' => 'Temperature', 'type' => 'number', 'default' => 0.4],
            ['key' => 'max_tokens', 'label' => 'Max Tokens', 'type' => 'number', 'default' => 1000],
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
            'title'  => 'Contoh: agent cek halaman lalu rangkum',
            'input'  => ['url' => 'https://situs-anda.com/produk'],
            'params' => [
                'model'         => 'openai/gpt-4o-mini',
                'system'        => 'Kamu auditor website. Gunakan tool yang tersedia untuk memeriksa, lalu jawab singkat.',
                'prompt'        => 'Periksa halaman {{$json.url}} dan sebutkan 3 masalah utamanya.',
                'tools'         => '[{"name":"fetch_page","description":"Ambil isi HTML halaman","type":"http","method":"GET","url":"{{$json.url}}"}]',
                'max_iterations' => 4,
                'temperature'   => 0.3,
                'max_tokens'    => 800,
            ],
        ]];
    }

    public function getExampleOutput(): array
    {
        return ['main' => [['json' => [
            'content'     => '1. H1 ganda 2. Gambar tanpa alt 3. Canonical hilang',
            'iterations'  => 2,
            'tool_trace'  => [['tool' => 'fetch_page', 'status' => 'ok']],
        ]]]];
    }

    use AiBudgetGuardTrait;

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $this->guardAiBudget($context);
        $credential = $context->parameters['credential'] ?? null;
        if (! is_array($credential)) {
            throw new \Exception('Pilih credential AI pada node ini (atau andalkan credential Default).');
        }
        $apiKey   = $credential['api_key'] ?? null;
        $baseUrl  = rtrim($credential['base_url'] ?? $this->defaultBaseUrl(), '/');
        if (! $apiKey) {
            throw new \Exception('Credential AI tidak memiliki API Key.');
        }

        $item = $inputItems[0] ?? [];
        $toolsRaw = (string) ($params['tools'] ?? '');
        $tools = [];
        if ($toolsRaw !== '') {
            $decoded = json_decode($toolsRaw, true);
            if (! is_array($decoded)) {
                throw new \Exception('Tools JSON tidak valid.');
            }
            $tools = $decoded;
        }

        $maxIter = max(1, (int) ($params['max_iterations'] ?? 5));
        $memoryKey = trim((string) ($params['memory_key'] ?? ''));

        // Susun pesan awal: system + memory + user
        $messages = [];
        if (! empty($params['system'])) {
            $messages[] = ['role' => 'system', 'content' => (string) $params['system']];
        }
        if ($memoryKey !== '') {
            foreach ($this->loadMemory($memoryKey) as $m) {
                $messages[] = ['role' => $m['role'], 'content' => (string) $m['content']];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $context->resolve((string) ($params['prompt'] ?? ''), $item)];

        $trace = [];
        $iterations = 0;
        $finalContent = null;
        $lastUsage = null;
        $lastModel = (string) ($params['model'] ?? 'openai/gpt-4o-mini');

        while ($iterations < $maxIter) {
            $iterations++;

            $payload = [
                'model'       => $context->resolve((string) ($params['model'] ?? 'openai/gpt-4o-mini'), $item),
                'messages'    => $messages,
                'temperature' => (float) ($params['temperature'] ?? 0.4),
                'max_tokens'  => (int) ($params['max_tokens'] ?? 1000),
            ];
            if ($tools !== [] && $finalContent === null) {
                $payload['tools'] = $this->toOpenAiTools($tools);
            }

            $body = $this->llmPostJson($baseUrl . '/chat/completions', $payload, (string) $apiKey);
            $response = json_decode((string) $body, true);
            if (! isset($response['choices'][0]['message'])) {
                throw new \Exception('AI API error: ' . substr((string) $body, 0, 300));
            }

            $lastUsage  = $response['usage'] ?? $lastUsage;
            $lastModel  = (string) ($response['model'] ?? $payload['model']);

            $msg = $response['choices'][0]['message'];

            if (! empty($msg['tool_calls'])) {
                // Simpan assistant message dengan tool_calls agar protokol valid.
                $messages[] = [
                    'role'       => 'assistant',
                    'content'    => $msg['content'] ?? '',
                    'tool_calls' => $msg['tool_calls'],
                ];

                foreach ($msg['tool_calls'] as $call) {
                    $fnName = $call['function']['name'] ?? '';
                    $argsJson = $call['function']['arguments'] ?? '{}';
                    $result = $this->runTool($tools, $fnName, $argsJson, $item, $context);
                    $trace[] = ['tool' => $fnName, 'status' => $result['ok'] ? 'ok' : 'error'];
                    $messages[] = [
                        'role'       => 'tool',
                        'tool_call_id' => $call['id'] ?? ('call_' . $iterations),
                        'content'    => json_encode($result, JSON_UNESCAPED_UNICODE),
                    ];
                }
                continue; // kembali ke LLM dengan hasil tool
            }

            $finalContent = (string) ($msg['content'] ?? '');
            break;
        }

        if ($finalContent === null) {
            $finalContent = '(Batas iterasi tool tercapai tanpa jawaban final.)';
        }

        if ($memoryKey !== '') {
            $userPrompt = (string) ($params['prompt'] ?? '');
            $this->saveMemory($memoryKey, 'user', $context->resolve($userPrompt, $item));
            $this->saveMemory($memoryKey, 'assistant', $finalContent);
        }

        (new \App\Services\AiUsageService())->log(
            isset($context->workflow['id']) ? (int) $context->workflow['id'] : null,
            null,
            $lastModel,
            $lastUsage
        );

        return ['main' => [['json' => [
            'content'      => $finalContent,
            'parsed'       => $this->tryParseJson($finalContent),
            'iterations'   => $iterations,
            'tool_trace'   => $trace,
            'memory_key'   => $memoryKey !== '' ? $memoryKey : null,
        ]]]];
    }

    // ==================================================================
    // Tools
    // ==================================================================

    private function toOpenAiTools(array $tools): array
    {
        $out = [];
        foreach ($tools as $t) {
            $out[] = [
                'type' => 'function',
                'function' => [
                    'name'        => (string) ($t['name'] ?? ''),
                    'description' => (string) ($t['description'] ?? ''),
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => (object) [],
                    ],
                ],
            ];
        }

        return $out;
    }

    private function runTool(array $tools, string $name, string $argsJson, array $item, WorkflowContext $context): array
    {
        foreach ($tools as $t) {
            if (($t['name'] ?? '') !== $name) {
                continue;
            }

            try {
                $args = json_decode($argsJson ?: '{}', true) ?: [];

                if (($t['type'] ?? 'http') === 'workflow') {
                    $engine = new \App\Services\Workflow\WorkflowEngine();
                    $wfId = (int) ($t['workflow_id'] ?? 0);
                    $wfRow = \Config\Database::connect()
                        ->table('workflows')->where('id', $wfId)->get()->getRowArray();
                    if (! $wfRow) {
                        return ['ok' => false, 'error' => "Workflow #{$wfId} tidak ditemukan"];
                    }
                    $result = $engine->run($wfRow, [['json' => $args]], 'manual');
                    unset($result['order']);

                    return ['ok' => ($result['status'] ?? '') === 'success', 'result' => $result];
                }

                $method  = strtoupper((string) ($t['method'] ?? 'GET'));
                $url     = $context->resolve((string) ($t['url'] ?? ''), $item);
                $headers = [];
                foreach ((array) ($t['headers'] ?? []) as $hk => $hv) {
                    $headers[] = $hk . ': ' . $context->resolve((string) $hv, $item);
                }
                $bodyTemplate = $t['body'] ?? null;

                $ch = curl_init($url);
                $opts = [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => (int) ($t['timeout'] ?? 30),
                ];
                if ($method === 'POST') {
                    $opts[CURLOPT_POST] = true;
                    $opts[CURLOPT_POSTFIELDS] = $context->resolve((string) $bodyTemplate, $item);
                } else {
                    $opts[CURLOPT_HTTPGET] = true;
                }
                if ($headers !== []) {
                    $opts[CURLOPT_HTTPHEADER] = $headers;
                }
                curl_setopt_array($ch, $opts);
                $resp  = curl_exec($ch);
                $err   = curl_error($ch);
                $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);

                if ($err) {
                    return ['ok' => false, 'error' => $err];
                }

                $decoded = json_decode((string) $resp, true);

                return [
                    'ok'     => $code < 400,
                    'status' => $code,
                    'data'   => $decoded !== null ? $decoded : mb_substr((string) $resp, 0, 4000),
                ];
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        return ['ok' => false, 'error' => "Tool '{$name}' tidak terdaftar"];
    }

    // ==================================================================
    // Memory
    // ==================================================================

    private function loadMemory(string $key): array
    {
        try {
            return \Config\Database::connect()
                ->table('ai_memories')
                ->select('role, content')
                ->where('memory_key', $key)
                ->orderBy('id', 'DESC')
                ->limit(20)
                ->get()
                ->getResultArray() ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function saveMemory(string $key, string $role, string $content): void
    {
        try {
            \Config\Database::connect()->table('ai_memories')->insert([
                'memory_key' => mb_substr($key, 0, 191),
                'role'       => in_array($role, ['user', 'assistant', 'tool'], true) ? $role : 'assistant',
                'content'    => mb_substr($content, 0, 60000),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[AiAgent] Gagal simpan memory: ' . $e->getMessage());
        }
    }

    /**
     * Panggil LLM. Protected agar test bisa membuat stub.
     */
    protected function llmPostJson(string $url, array $payload, string $apiKey): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
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

    protected function defaultBaseUrl(): string
    {
        return 'https://api.9router.com';
    }

    protected function tryParseJson(?string $content)
    {
        if ($content === null) {
            return null;
        }
        $d = json_decode($content, true);

        return json_last_error() === JSON_ERROR_NONE ? $d : null;
    }
}
