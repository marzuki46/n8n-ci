<?php

namespace App\Nodes;

class GithubNode extends AbstractNode
{
    public function getType(): string
    {
        return 'github';
    }

    public function getName(): string
    {
        return 'GitHub';
    }

    public function getCategory(): string
    {
        return 'Integrations';
    }

    public function getColor(): string
    {
        return '#24292f';
    }

    public function getIcon(): string
    {
        return 'github';
    }

    public function getDescription(): string
    {
        return 'Buat repositori, gist, atau issue di GitHub.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'         => 'credential',
                'label'       => 'Credential GitHub',
                'type'        => 'credentials',
                'credentialType' => 'github',
                'required'    => true,
            ],
            [
                'key'      => 'resource',
                'label'    => 'Sumber',
                'type'     => 'select',
                'options'  => ['repos', 'gists', 'issues'],
                'default'  => 'repos',
            ],
            [
                'key'      => 'operation',
                'label'    => 'Operasi',
                'type'     => 'select',
                'options'  => ['create', 'list'],
                'default'  => 'create',
            ],
            [
                'key'         => 'name',
                'label'       => 'Nama Repo / Gist / Owner',
                'type'        => 'text',
                'placeholder' => 'nama-repo-anda',
            ],
            [
                'key'         => 'repo',
                'label'       => 'Nama Repo (untuk issue)',
                'type'        => 'text',
                'placeholder' => 'owner/repo',
            ],
            [
                'key'         => 'title',
                'label'       => 'Judul Issue / Deskripsi',
                'type'        => 'textarea',
                'placeholder' => 'Deskripsi singkat',
            ],
            [
                'key'         => 'body',
                'label'       => 'Isi (gist / issue)',
                'type'        => 'textarea',
                'placeholder' => 'Konten gist atau body issue',
            ],
            [
                'key'     => 'retryCount',
                'label'   => 'Retry Saat Gagal',
                'type'    => 'number',
                'default' => 0,
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
            throw new \Exception('Pilih credential GitHub pada node ini.');
        }

        $token = (string) ($credential['token'] ?? '');
        if ($token === '') {
            throw new \Exception('Token GitHub kosong.');
        }

        $resource  = (string) ($params['resource'] ?? 'repos');
        $operation = (string) ($params['operation'] ?? 'create');

        $items = [];
        foreach ($inputItems as $item) {
            $contextItem = is_array($item) ? $item : ['json' => $item];
            $name  = (string) $context->resolve((string) ($params['name'] ?? ''), $contextItem);
            $title = (string) $context->resolve((string) ($params['title'] ?? ''), $contextItem);
            $body  = (string) $context->resolve((string) ($params['body'] ?? ''), $contextItem);
            $repo  = (string) $context->resolve((string) ($params['repo'] ?? ''), $contextItem);

            $data = $this->call($token, $resource, $operation, $name, $title, $body, $repo);

            $items[] = ['json' => ['resource' => $resource, 'operation' => $operation, 'data' => $data]];
        }

        return ['main' => $items];
    }

    protected function call(string $token, string $resource, string $operation, string $name, string $title, string $body, string $repo): array
    {
        $base = 'https://api.github.com';
        $url  = $base;
        $method = 'POST';
        $payload = null;

        if ($resource === 'repos') {
            if ($operation === 'list') {
                $url = $base . '/user/repos?per_page=30';
                $method = 'GET';
            } else {
                $url = $base . '/user/repos';
                $payload = ['name' => $name, 'description' => $title, 'private' => false];
            }
        } elseif ($resource === 'gists') {
            if ($operation === 'list') {
                $url = $base . '/gists?per_page=30';
                $method = 'GET';
            } else {
                $url = $base . '/gists';
                $payload = ['description' => $title, 'public' => false, 'files' => [($name !== '' ? $name : 'file.txt') => ['content' => $body]]];
            }
        } elseif ($resource === 'issues') {
            if ($repo === '') {
                throw new \Exception('Repo (owner/repo) wajib diisi untuk list/buat issue.');
            }
            $url = $base . '/repos/' . $repo . '/issues';
            if ($operation === 'list') {
                $url .= '?per_page=30';
                $method = 'GET';
            } else {
                $payload = ['title' => $title, 'body' => $body];
            }
        }

        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.github+json',
            'User-Agent: FlowForge',
            'X-GitHub-Api-Version: 2022-11-28',
            'Content-Type: application/json',
        ];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'FlowForge',
        ];

        if ($method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        } else {
            $options[CURLOPT_POST] = true;
            if ($payload !== null) {
                $options[CURLOPT_POSTFIELDS] = json_encode($payload);
            }
        }

        curl_setopt_array($ch, $options);
        $responseBody = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($error || $httpCode >= 400) {
            throw new \Exception('GitHub API error (' . $httpCode . '): ' . $responseBody);
        }

        $decoded = json_decode((string) $responseBody, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : ['raw' => $responseBody];
    }

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: buat issue laporan bug',
    'input' => 
    array (
      'repo' => 'userkamu/repo',
      'judul' => 'Form tidak tersimpan',
      'detail' => 'Klik save tidak ada respons.',
    ),
    'params' => 
    array (
      'resource' => 'issues',
      'operation' => 'create',
      'repo' => '{{$json.repo}}',
      'title' => 'Bug: {{$json.judul}}',
      'body' => 'Detail: {{$json.detail}}',
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
        'number' => 42,
        'state' => 'open',
        'html_url' => 'https://github.com/user/repo/issues/42',
      ),
    ),
  ),
);
    }
}
