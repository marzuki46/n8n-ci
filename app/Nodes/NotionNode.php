<?php

namespace App\Nodes;

class NotionNode extends AbstractNode
{
    public function getType(): string
    {
        return 'notion';
    }

    public function getName(): string
    {
        return 'Notion';
    }

    public function getCategory(): string
    {
        return 'Integrations';
    }

    public function getColor(): string
    {
        return '#151515';
    }

    public function getIcon(): string
    {
        return 'notion';
    }

    public function getDescription(): string
    {
        return 'Cari halaman/database atau buat halaman di Notion.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'         => 'credential',
                'label'       => 'Credential Notion',
                'type'        => 'credentials',
                'credentialType' => 'notion',
                'required'    => true,
            ],
            [
                'key'      => 'operation',
                'label'    => 'Operasi',
                'type'     => 'select',
                'options'  => ['search', 'createPage'],
                'default'  => 'search',
            ],
            [
                'key'         => 'query',
                'label'       => 'Query Pencarian',
                'type'        => 'text',
                'placeholder' => 'kata kunci',
            ],
            [
                'key'         => 'database_id',
                'label'       => 'Database ID (createPage)',
                'type'        => 'text',
                'placeholder' => 'xxxxxxxx-xxxx-...',
            ],
            [
                'key'         => 'title',
                'label'       => 'Judul Halaman',
                'type'        => 'textarea',
                'placeholder' => '{{$json.title}}',
            ],
            [
                'key'         => 'properties',
                'label'       => 'Properti (JSON)',
                'type'         => 'code',
                'default'      => '{}',
                'description'  => 'Properti tambahan (tanpa kolom title). Contoh: {"Status":{"select":{"name":"Draft"}}}',
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
            throw new \Exception('Pilih credential Notion pada node ini.');
        }

        $token = (string) ($credential['token'] ?? '');
        if ($token === '') {
            throw new \Exception('Token Notion kosong.');
        }

        $operation = (string) ($params['operation'] ?? 'search');

        $items = [];
        foreach ($inputItems as $item) {
            $contextItem = is_array($item) ? $item : ['json' => $item];
            $data = $this->call($token, $operation, $params, $context, $contextItem);
            $items[] = ['json' => ['operation' => $operation, 'data' => $data]];
        }

        return ['main' => $items];
    }

    protected function call(string $token, string $operation, array $params, WorkflowContext $context, array $contextItem): array
    {
        $base = 'https://api.notion.com';
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Notion-Version: 2022-06-28',
        ];

        if ($operation === 'search') {
            $url = $base . '/v1/search';
            $query = (string) $context->resolve((string) ($params['query'] ?? ''), $contextItem);
            $payload = $query !== '' ? ['query' => $query, 'page_size' => 10] : ['page_size' => 10];
        } else {
            $url = $base . '/v1/pages';
            $title = (string) $context->resolve((string) ($params['title'] ?? ''), $contextItem);
            $databaseId = (string) $context->resolve((string) ($params['database_id'] ?? ''), $contextItem);
            $properties = $context->resolveDeep($params['properties'] ?? '{}', $contextItem);
            $properties = is_array($properties) ? $properties : [];

            $titleProperty = [
                'title' => [['text' => ['content' => $title]]],
            ];

            $properties = array_merge($titleProperty, $properties);

            $payload = ['parent' => ['database_id' => $databaseId], 'properties' => $properties];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $responseBody = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($error || $httpCode >= 400) {
            throw new \Exception('Notion API error (' . $httpCode . '): ' . $responseBody);
        }

        $decoded = json_decode((string) $responseBody, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : ['raw' => $responseBody];
    }

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: tambah baris ke database Notion',
    'input' => 
    array (
      'tugas' => 'Follow up klien',
      'prioritas' => 'Tinggi',
    ),
    'params' => 
    array (
      'operation' => 'create_page',
      'database_id' => 'abc123databaseid',
      'title' => '{{$json.tugas}}',
      'properties' => '{"Prioritas":"{{$json.prioritas}}"}',
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
        'id' => 'page-id-123',
        'created_time' => '2026-08-24T01:00:00Z',
      ),
    ),
  ),
);
    }
}
