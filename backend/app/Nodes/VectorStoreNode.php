<?php

namespace App\Nodes;

use App\Services\AiVectorService;

/**
 * Vector Store (RAG foundation): simpan dokumen sebagai embeddings,
 * atau cari top-k dokumen paling mirip dengan query.
 */
class VectorStoreNode extends AbstractNode
{
    public function getType(): string
    {
        return 'vector_store';
    }

    public function getName(): string
    {
        return 'Vector Store';
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
        return 'database';
    }

    public function getDescription(): string
    {
        return 'Simpan teks sebagai embeddings (upsert) atau cari dokumen paling mirip dengan query (search). Fondasi RAG.';
    }

    public function getParameters(): array
    {
        return [
            ['key' => 'credential', 'label' => 'Credential AI', 'type' => 'credentials', 'credentialType' => 'openai'],
            ['key' => 'operation', 'label' => 'Operasi', 'type' => 'select',
             'options' => [
                 ['value' => 'search', 'label' => 'Search: cari dokumen mirip'],
                 ['value' => 'upsert', 'label' => 'Upsert: simpan dokumen'],
                 ['value' => 'delete', 'label' => 'Delete: hapus namespace'],
             ],
             'default' => 'search'],
            ['key' => 'model', 'label' => 'Model Embedding', 'type' => 'text', 'default' => 'openai/text-embedding-3-small'],
            ['key' => 'namespace', 'label' => 'Namespace', 'type' => 'text', 'required' => true, 'placeholder' => 'kb-produk'],
            ['key' => 'query_field', 'label' => 'Field Query/Text', 'type' => 'text', 'default' => 'text',
             'description' => 'Search: field berisi query. Upsert: field berisi isi dokumen.'],
            ['key' => 'source_field', 'label' => 'Field Sumber (opsional)', 'type' => 'text', 'placeholder' => 'url'],
            ['key' => 'top_k', 'label' => 'Top K Hasil', 'type' => 'number', 'default' => 5],
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
        return [
            [
                'title'  => 'Contoh: cari dokumen yang relevan dengan pertanyaan',
                'input'  => ['text' => 'Bagaimana cara reset password?'],
                'params' => [
                    'operation'   => 'search',
                    'namespace'   => 'kb-faq',
                    'query_field' => 'text',
                    'top_k'       => 3,
                ],
            ],
            [
                'title'  => 'Contoh: simpan artikel ke knowledge base',
                'input'  => ['text' => 'Isi artikel lengkap...', 'url' => 'https://situs/artikel'],
                'params' => [
                    'operation'    => 'upsert',
                    'namespace'    => 'kb-artikel',
                    'query_field'  => 'text',
                    'source_field' => 'url',
                ],
            ],
        ];
    }

    public function getExampleOutput(): array
    {
        return ['main' => [['json' => [
            'score'     => 0.93,
            'content'   => 'Untuk reset password, klik "Lupa password" di halaman login...',
            'source'    => 'https://situs/kb/reset-password',
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

        $service   = new AiVectorService();
        $op        = (string) ($params['operation'] ?? 'search');
        $namespace = $context->resolve((string) ($params['namespace'] ?? ''), $inputItems[0] ?? []);
        $model     = (string) ($params['model'] ?? 'openai/text-embedding-3-small');
        $field     = (string) ($params['query_field'] ?? 'text');
        $wsId      = isset($context->workflow['workspace_id']) ? (int) $context->workflow['workspace_id'] : null;

        if ($namespace === '') {
            throw new \Exception('Namespace wajib diisi.');
        }

        // Delete tidak butuh embeddings.
        if ($op === 'delete') {
            $deleted = $service->deleteNamespace($wsId, $namespace);

            return ['main' => [['json' => ['deleted' => true, 'count' => $deleted]]]];
        }

        if ($op === 'upsert') {
            $docs = [];
            foreach ($inputItems as $item) {
                $data = is_array($item) && array_key_exists('json', $item) ? $item['json'] : $item;
                $text = trim(is_array($data) ? (string) ($data[$field] ?? '') : '');
                if ($text === '') {
                    continue;
                }
                $doc = ['content' => $text];
                if (! empty($params['source_field']) && is_array($data)) {
                    $doc['source'] = (string) ($data[$params['source_field']] ?? '');
                }
                $docs[] = $doc;
            }
            if ($docs === []) {
                throw new \Exception('Tidak ada konten untuk disimpan (field "' . $field . '" kosong).');
            }

            $vectors = $service->embed(
                $credential,
                array_column($docs, 'content'),
                $context->resolve($model, $inputItems[0] ?? []),
                isset($context->workflow['id']) ? (int) $context->workflow['id'] : null
            );
            $saved = $service->upsert($wsId, $namespace, $docs, $vectors);

            return ['main' => [['json' => ['stored' => $saved, 'namespace' => $namespace]]]];
        }

        // SEARCH
        $queryItem = $inputItems[0] ?? [];
        $qData = is_array($queryItem) && array_key_exists('json', $queryItem)
            ? $queryItem['json']
            : (is_array($queryItem) ? $queryItem : []);
        $queryText = trim(is_array($qData) ? (string) ($qData[$field] ?? '') : '');

        if ($queryText === '') {
            throw new \Exception('Query kosong — isi field "' . $field . '" pada item input.');
        }

        [$qv] = $service->embed(
            $credential,
            [$queryText],
            $context->resolve($model, $inputItems[0] ?? []),
            isset($context->workflow['id']) ? (int) $context->workflow['id'] : null
        );

        $hits = $service->search($wsId, $namespace, $qv, max(1, (int) ($params['top_k'] ?? 5)));

        if ($hits === []) {
            return ['main' => [['json' => ['results' => [], 'total' => 0, 'query' => $queryText]]]];
        }

        $items = [];
        foreach ($hits as $h) {
            $items[] = ['json' => [
                'score'   => round((float) $h['score'], 4),
                'content' => mb_substr((string) $h['content'], 0, 4000),
                'source'  => $h['source'],
            ]];
        }

        return ['main' => $items];
    }
}
