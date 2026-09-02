<?php

namespace App\Services;

/**
 * Paket 3 — Content AI untuk plugin WordPress.
 * Generate/lanjutkan konten lewat LLM kompatibel OpenAI, memakai
 * credential AI **default proyek** (hasil Paket 1).
 *
 * httpPostJson() sengaja protected agar test bisa membuat stub subclass.
 */
class WpContentService
{
    protected $db;

    protected CredentialService $credentials;

    public function __construct($db = null)
    {
        $this->db         = $db ?? \Config\Database::connect();
        $this->credentials = new CredentialService();
    }

    /**
     * Cari credential AI default workspace (slug openai/9router).
     */
    public function findAiCredential(int $workspaceId): ?array
    {
        foreach (['openai', '9router'] as $slug) {
            $typeRow = $this->db->table('credential_types')->where('slug', $slug)->get()->getRowArray();
            if (! $typeRow) {
                continue;
            }

            $cred = $this->credentials->findDefault($workspaceId, (int) $typeRow['id']);
            if ($cred) {
                return $cred;
            }
        }

        return null;
    }

    /**
     * Status untuk halaman setting plugin WP.
     */
    public function status(int $workspaceId): array
    {
        $workspace = $this->db->table('workspaces')->where('id', $workspaceId)->get()->getRowArray();

        return [
            'valid'              => true,
            'workspace_name'     => $workspace['name'] ?? null,
            'ai_credential_ready' => $this->findAiCredential($workspaceId) !== null,
        ];
    }

    /**
     * Buat konten baru.
     *
     * @param array $opts {topic, content_type, language, min_words,
     *                     company_profile, instructions, model}
     */
    public function generate(array $aiCredential, array $opts): array
    {
        $topic      = trim((string) ($opts['topic'] ?? ''));
        if ($topic === '') {
            throw new \InvalidArgumentException('Topik wajib diisi.');
        }

        $contentType = (string) ($opts['content_type'] ?? 'post');
        $language    = (string) ($opts['language'] ?? 'id');
        $minWords    = max(50, (int) ($opts['min_words'] ?? 600));
        $company     = trim((string) ($opts['company_profile'] ?? ''));
        $instructions = trim((string) ($opts['instructions'] ?? ''));

        $typeLabel = [
            'post'   => 'artikel blog',
            'page'   => 'halaman statis',
            'product' => 'deskripsi produk WooCommerce',
            'archive_tag' => 'arsip tag',
            'archive_category' => 'arsip kategori',
        ][$contentType] ?? 'artikel blog';

        $langLabel = $language === 'en'
            ? 'English'
            : 'Bahasa Indonesia';

        $system = 'Kamu adalah penulis konten profesional. Tulis dalam ' . $langLabel . '. '
            . 'Gaya penulisan natural, humanis, tidak berlebihan (tanpa clickbait dan tanpa kata berlebihan seperti "sangat" berulang). '
            . 'Gunakan struktur dengan subjudul (H2/H3) dalam format HTML. Jangan tulis penjelasan di luar konten.';
        if ($company !== '') {
            $system .= ' Profil perusahaan (sebutkan secara soft-selling dan konsisten dengan branding): ' . $company;
        }

        $prompt = 'Tulis sebuah ' . $typeLabel . ' dengan topik: "' . $topic . '". '
            . 'Target panjang minimal sekitar ' . $minWords . ' kata. '
            . 'Mulai dari judul (H1 tidak perlu, mulai dari pengantar), lalu isi yang mengalir dengan subjudul.';
        if ($instructions !== '') {
            $prompt .= "\nInstruksi tambahan: " . $instructions;
        }
        $prompt .= "\nBalas HANYA konten HTML-nya.";

        return $this->callLlm($aiCredential, [
            'model'       => $opts['model'] ?? 'openai/gpt-4o-mini',
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.7,
            'max_tokens'  => max(1000, $minWords * 3),
        ]);
    }

    /**
     * Lanjutkan/mutakhirkan konten yang sudah ada.
     *
     * @param array $opts {existing_content, existing_title?, language,
     *                     company_profile, instructions, action, model?}
     */
    public function continueContent(array $aiCredential, array $opts): array
    {
        $existing = trim((string) ($opts['existing_content'] ?? ''));
        if ($existing === '') {
            throw new \InvalidArgumentException('Konten yang sudah ada wajib diisi.');
        }

        $action   = (string) ($opts['action'] ?? 'expand');
        $language = (string) ($opts['language'] ?? 'id');
        $company  = trim((string) ($opts['company_profile'] ?? ''));
        $instr    = trim((string) ($opts['instructions'] ?? ''));
        $title    = trim((string) ($opts['existing_title'] ?? ''));

        $langLabel = $language === 'en' ? 'English' : 'Bahasa Indonesia';
        $actionLabel = [
            'rewrite' => 'tulis ulang dengan struktur dan gaya yang lebih baik tanpa mengubah makna inti',
            'expand'  => 'perluas dan perdalam (tambah subbab, contoh, dan penjelasan)',
            'polish'  => 'rapikan tata bahasa, ejaan, dan alur tanpa menambah materi baru secara signifikan',
        ][$action] ?? 'perluas';

        $system = 'Kamu adalah editor konten profesional yang bekerja dalam ' . $langLabel . '. '
            . 'Jaga konsistensi branding dan gaya soft-selling.';
        if ($company !== '') {
            $system .= ' Profil perusahaan: ' . $company;
        }

        $prompt = ($title !== '' ? 'Judul konten: "' . $title . "\"\n\n" : '')
            . "Konten saat ini:\n" . $existing . "\n\n"
            . 'Tugas: ' . $actionLabel . '.';
        if ($instr !== '') {
            $prompt .= "\nInstruksi tambahan: " . $instr;
        }
        $prompt .= "\nBalas HANYA konten HTML hasil akhirnya.";

        return $this->callLlm($aiCredential, [
            'model'       => $opts['model'] ?? 'openai/gpt-4o-mini',
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => $action === 'polish' ? 0.3 : 0.7,
            'max_tokens'  => 4000,
        ]);
    }

    /**
     * Panggil chat completion kompatibel OpenAI.
     */
    protected function callLlm(array $credential, array $payload): array
    {
        $apiKey = $credential['api_key'] ?? null;
        if (! $apiKey) {
            throw new \RuntimeException('Belum ada AI credential default di proyek ini. Tandai satu sebagai Default di halaman Credentials.');
        }

        $baseUrl = rtrim($credential['base_url'] ?? 'https://api.9router.com', '/');
        $body    = $this->httpPostJson($baseUrl . '/chat/completions', $payload, (string) $apiKey);

        $response = json_decode((string) $body, true);
        if (! isset($response['choices'][0]['message']['content'])) {
            throw new \RuntimeException('AI API error: ' . ($response['error']['message'] ?? substr((string) $body, 0, 300)));
        }

        $content = (string) $response['choices'][0]['message']['content'];

        return [
            'content'    => $content,
            'word_count' => $this->countWords($content),
            'model'      => $response['model'] ?? ($payload['model'] ?? null),
            'usage'      => $response['usage'] ?? null,
        ];
    }

    /**
     * POST JSON ke endpoint LLM. Protected agar bisa di-stub di test.
     */
    protected function httpPostJson(string $url, array $payload, string $apiKey): string
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

        $responseBody = curl_exec($ch);
        $error        = curl_error($ch);
        $httpCode     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($error || $httpCode >= 400) {
            throw new \RuntimeException('AI API error (' . $httpCode . '): ' . $error);
        }

        return (string) $responseBody;
    }

    /**
     * Hitung kata pada konten (HTML di-strip dulu). Aman untuk teks ber-UTF8.
     */
    public function countWords(string $html): int
    {
        // Tag diganti spasi agar kata antar-elemen blok tidak menempel
        // ("dua</h2><p>tiga" tidak menjadi "duatiga").
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/<[^>]+>/', ' ', $html)));
        if ($text === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $text));
    }
}
