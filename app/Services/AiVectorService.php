<?php

namespace App\Services;

/**
 * Vector store sederhana untuk RAG: embeddings via API kompatibel OpenAI,
 * penyimpanan di MySQL, pencarian cosine-similarity di PHP.
 * Cukup untuk ratusan–ribuan vektor per namespace.
 */
class AiVectorService
{
    protected $db;

    public function __construct($db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    // ==================================================================
    // Embeddings
    // ==================================================================

    /**
     * Panggil endpoint /embeddings. Protected agar bisa di-stub di test.
     *
     * @return array list<float[]> vektor sesuai urutan input
     */
    public function embed(array $credential, array $texts, string $model = 'openai/text-embedding-3-small', ?int $workflowId = null): array
    {
        if (empty($credential['api_key'])) {
            throw new \RuntimeException('Credential AI tidak memiliki API Key.');
        }
        $baseUrl = rtrim($credential['base_url'] ?? 'https://api.9router.com', '/');

        $body = $this->httpPostJson($baseUrl . '/embeddings', [
            'model' => $model,
            'input' => array_values($texts),
        ], (string) $credential['api_key']);

        $resp = json_decode((string) $body, true);
        if (! isset($resp['data']) || ! is_array($resp['data'])) {
            throw new \RuntimeException('Embeddings API error: ' . substr((string) $body, 0, 200));
        }

        // Log pemakaian token embeddings (best-effort).
        (new AiUsageService())->log($workflowId, null, $resp['model'] ?? $model, $resp['usage'] ?? null);

        usort($resp['data'], static fn ($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        return array_map(
            static fn ($row) => array_map('floatval', $row['embedding'] ?? []),
            $resp['data']
        );
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
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($err || $code >= 400) {
            throw new \RuntimeException('Embeddings API error (' . $code . '): ' . $err);
        }

        return (string) $resp;
    }

    // ==================================================================
    // Upsert / Search / Delete
    // ==================================================================

    /**
     * Simpan dokumen beserta vektornya.
     *
     * @param array $docs [{content, source?, meta?}]
     */
    public function upsert(?int $workspaceId, string $namespace, array $docs, array $vectors, ?int $workflowId = null): int
    {
        if (count($docs) !== count($vectors)) {
            throw new \InvalidArgumentException('Jumlah docs dan vectors tidak sama.');
        }

        $now = date('Y-m-d H:i:s');
        $count = 0;

        foreach ($docs as $i => $doc) {
            $vec = $vectors[$i];
            if (! is_array($vec) || $vec === []) {
                continue;
            }

            $this->db->table('ai_vectors')->insert([
                'workspace_id' => $workspaceId,
                'namespace'    => mb_substr($namespace, 0, 100),
                'source'       => isset($doc['source']) ? mb_substr((string) $doc['source'], 0, 191) : null,
                'content'      => (string) ($doc['content'] ?? ''),
                'vector'       => json_encode($vec),
                'dims'         => count($vec),
                'meta_json'    => isset($doc['meta']) ? json_encode($doc['meta'], JSON_UNESCAPED_UNICODE) : null,
                'created_at'   => $now,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Cari top-k dokumen paling mirip dengan query text.
     *
     * @return array [{score, content, source, meta}]
     */
    public function search(?int $workspaceId, string $namespace, array $queryVector, int $topK = 5): array
    {
        $rows = $this->db->table('ai_vectors')
            ->where('namespace', $namespace)
            ->when($workspaceId !== null, static fn ($b) => $b->groupStart()
                ->where('workspace_id', $workspaceId)
                ->orWhere('workspace_id', null)
                ->groupEnd())
            ->limit(5000)
            ->get()
            ->getResultArray();

        $scored = [];
        foreach ($rows as $row) {
            $vec = json_decode((string) $row['vector'], true);
            if (! is_array($vec)) {
                continue;
            }
            $scored[] = [
                'score'   => $this->cosineSimilarity($queryVector, $vec),
                'content' => (string) $row['content'],
                'source'  => $row['source'],
                'meta'    => json_decode((string) ($row['meta_json'] ?? ''), true),
                'id'      => (int) $row['id'],
            ];
        }

        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, max(1, $topK));
    }

    public function deleteNamespace(?int $workspaceId, string $namespace, ?int $workflowId = null): int
    {
        $b = $this->db->table('ai_vectors')->where('namespace', $namespace);
        if ($workspaceId !== null && $workflowId === null) {
            $b->where('workspace_id', $workspaceId);
        }
        $b->delete();

        return $this->db->affectedRows();
    }

    // ==================================================================

    /**
     * Cosine similarity antara dua vektor float. Vektor beda dimensi
     * dinormalisasi ke dimensi terkecil (aman dari embedding mismatch).
     */
    public function cosineSimilarity(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }

        $dot = 0.0; $na = 0.0; $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na  += $a[$i] * $a[$i];
            $nb  += $b[$i] * $b[$i];
        }
        if ($na == 0.0 || $nb == 0.0) {
            return 0.0;
        }

        return (float) ($dot / (sqrt($na) * sqrt($nb)));
    }
}
