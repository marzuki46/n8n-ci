<?php

namespace App\Nodes;

/**
 * Edit ulang draf agar tidak terdengar seperti hasil AI (no-ai-slop),
 * memakai aturan editing dari github.com/petergyang/no-ai-slop.
 */
class NoAISlopNode extends AbstractNode
{
    use LlmCallerTrait;

    protected function defaultBaseUrl(): string
    {
        return 'https://api.9router.com/v1';
    }

    public function getType(): string
    {
        return 'no_ai_slop';
    }

    public function getName(): string
    {
        return 'No-AI Slop';
    }

    public function getCategory(): string
    {
        return 'AI';
    }

    public function getColor(): string
    {
        return '#e25563';
    }

    public function getIcon(): string
    {
        return 'slop';
    }

    public function getDescription(): string
    {
        return 'Edit draf agar lebih manusiawi & segar dengan aturan no-ai-slop.';
    }

    public function getExamples(): array
    {
        return [
            [
                'title'  => 'Contoh: edit ulang draf',
                'input'  => ['draft' => 'Di era digital yang terus berkembang pesat, pentingnya teknologi tidak dapat dipungkiri.'],
                'params' => [
                    'model'  => 'openai/gpt-4o-mini',
                    'mode'   => 'rewrite_only',
                    'content' => '{{$json.draft}}',
                ],
            ],
        ];
    }

    public function getExampleOutput(): array
    {
        return [
            'main' => [[
                'json' => [
                    'content' => 'Teknologi kini jadi bagian hidup sehari-hari. Tanpa sadar, kita memakainya dari bangun tidur sampai tidur lagi.',
                    'model'   => 'openai/gpt-4o-mini',
                ],
            ]],
        ];
    }

    public function getParameters(): array
    {
        return [
            [
                'key'         => 'credential',
                'label'       => 'Credential AI',
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
                'key'      => 'mode',
                'label'    => 'Mode Edit',
                'type'     => 'select',
                'options'  => [
                    ['value' => 'rewrite_only', 'label' => 'Tulis ulang (konten fresh)'],
                    ['value' => 'with_changelog', 'label' => 'Tulis ulang + daftar perubahan'],
                ],
                'default'  => 'rewrite_only',
            ],
            [
                'key'      => 'content',
                'label'    => 'Draf yang Diedit',
                'type'     => 'textarea',
                'required' => true,
                'placeholder' => '{{$json.content}}',
                'description' => 'Ambil konten dari node sebelumnya, mis. {{$json.content}}',
            ],
            [
                'key'      => 'extraInstructions',
                'label'    => 'Instruksi Tambahan',
                'type'     => 'textarea',
                'placeholder' => 'cth: pertahankan gaya santai, sasaran pembaca UMKM',
            ],
            [
                'key'     => 'temperature',
                'label'   => 'Temperature',
                'type'    => 'number',
                'default' => 0.4,
            ],
            [
                'key'     => 'max_tokens',
                'label'   => 'Max Tokens',
                'type'    => 'number',
                'default' => 4000,
            ],
            [
                'key'     => 'retryCount',
                'label'   => 'Retry Saat Gagal',
                'type'    => 'number',
                'default' => 1,
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
            throw new \Exception('Pilih credential AI pada node ini.');
        }

        $system = $this->systemPrompt((string) ($params['extraInstructions'] ?? ''), $params['mode'] ?? 'rewrite_only');

        $llmParams = [
            'model'           => $params['model'] ?? 'openai/gpt-4o-mini',
            'system'          => $system,
            'prompt'          => (string) ($params['content'] ?? '{{$json.content}}'),
            'temperature'     => $params['temperature'] ?? 0.4,
            'max_tokens'      => $params['max_tokens'] ?? 4000,
            'response_format' => 'text',
        ];

        return ['main' => $this->callLlm($credential, $llmParams, $inputItems, $context)];
    }

    protected function systemPrompt(string $extra, string $mode): string
    {
        $modeRule = $mode === 'with_changelog'
            ? "Output: SELALU sertakan bagian \"Apa yang diubah:\" (berapa baris) di akhir yang merangkum perubahan utama."
            : "Output: hanya teks hasil edit, tanpa intro, tanpa penjelasan, tanpa bagian \"Apa yang diubah\".";

        return trim(<<<TXT
Kamu adalah editor manusia yang tajam. Tugasmu: edit ulang draf menjadi lebih segar, jelas, dan tidak terdengar seperti tulisan AI, sambil MENJAGA makna, fakta, dan gaya pribadi penulis. Jangan menambah klaim, statistik, atau contoh baru. Bekerja dalam bahasa draf aslinya.

ATURAN INTI:
1. PERTAHANKAN suara penulis: perhatikan kosakata, ritme, kejujuran, humor, ketidakpastian. Jangan meratakan semua paragraf menjadi rapi seragam.
2. MINIMUM edit efektif: perbaiki pola AI, kesalahan, pengulangan, kalimat tidak jelas. Biarkan kalimat bagus tetap apa adanya.
3. LANGSUNG ke poin: buang pembuka yang bertele-tele ("yang perlu kamu tahu", "pada artikel ini", "mari kita mulai").
4. AWALI dengan kesimpulan bila membantu. Jangan paksa semua bagian berbentuk poin-detil-latar.
5. Suara AKTIF: "tim mengirimnya" > "keputusan dibuat". Jangan biarkan benda mati melakukan kata kerja manusia.
6. KONKRET, spesifik, beri nama/angka/contoh. Buang abstraksi kosong dan kalimat yang bisa dipindah ke produk/negara/konteks lain tanpa kehilangan arti (uji portabilitas).
7. JANGAN membangun tensi palsu: buang "bukan X, tapi Y", "yang tidak banyak orang tahu", "hal penting: ...", "ini bukan sekadar ...", "pertanyaannya bukan ...", "bayangkan jika ...".
8. Buang kata kata kunci slop: delve, foster, leverage, utilize, facilitate, empower, streamline, robust, cutting-edge, paradigm shift, game changer, transformative, elevate, embark, supercharge, harness, ever-evolving, tapestry, realm, beacon, meticulous, intricate, paramount, serta pengisi "penting untuk dicatat", "pada dasarnya", "sebenarnya", "di dunia yang", "pada akhirnya", "kesimpulannya", "ke depan".
9. Buang ending "fake-profound" dan metafora mic-drop. Akhiri pada kalimat konkret paling jelas. Jangan ringkas ulang isi di penutup.
10. JANGAN rotasi sinonim untuk variasi. Ulangi kata yang tepat.
11. Ganti "membuat keputusan" â†’ "memutuskan"; "memiliki kemampuan untuk" â†’ "bisa".
12. Em dash: maksimal 1-2 per tulisan panjang, nol untuk pendek. Hindari kalimat pecahan dramatis ("X. Dan Y.").
13. Buang komentar yang memberi tahu pembaca betapa pentingnya sesuatu ("ini menandai momen penting", "menyoroti komitmen", "menjadi bukti"). Tunjukkan lewat fakta.
14. Format mengikuti isi: jangan emoji di judul, jangan tebal mencolok di tengah kalimat, jangan bullet list bila 2 kalimat prosa lebih enak dibaca.

{$modeRule}
TXT) . ($extra !== '' ? "\n\nINSTRUKSI TAMBAHAN: " . $extra : '');
    }
}
