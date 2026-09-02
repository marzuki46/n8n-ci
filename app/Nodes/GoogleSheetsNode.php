<?php

namespace App\Nodes;

/**
 * Google Sheets — baca baris dari spreadsheet yang dibagikan publik
 * ("Publish to web" → CSV) atau URL export CSV mana pun.
 * Tanpa OAuth: cukup URL sheet. Output = satu item per baris (header jadi key).
 */
class GoogleSheetsNode extends AbstractNode
{
    public function getType(): string
    {
        return 'google_sheets_read';
    }

    public function getName(): string
    {
        return 'Google Sheets (Read)';
    }

    public function getCategory(): string
    {
        return 'Integrations';
    }

    public function getColor(): string
    {
        return '#25c290';
    }

    public function getIcon(): string
    {
        return 'table';
    }

    public function getDescription(): string
    {
        return 'Baca data dari Google Spreadsheet publik (File > Share > Publish to web, format CSV). Satu baris = satu item.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'         => 'url',
                'label'       => 'URL CSV Spreadsheet',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => 'https://docs.google.com/spreadsheets/d/e/.../pub?output=csv',
            ],
            [
                'key'      => 'limit',
                'label'    => 'Maks Baris (0 = semua)',
                'type'     => 'number',
                'default'  => 0,
            ],
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
            'title'  => 'Contoh: ambil daftar harga dari spreadsheet',
            'input'  => [],
            'params' => [
                'url'   => 'https://docs.google.com/spreadsheets/d/e/2PACX-.../pub?output=csv',
                'limit' => 50,
            ],
        ]];
    }

    public function getExampleOutput(): array
    {
        return ['main' => [
            ['json' => ['produk' => 'Laptop Pro', 'harga' => 15000000, 'stok' => 5]],
            ['json' => ['produk' => 'Mouse Gaming', 'harga' => 250000, 'stok' => 40]],
        ]];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $url = trim((string) ($params['url'] ?? ''));
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \Exception('URL spreadsheet wajib diisi dan harus valid.');
        }
        // Pastikan output CSV (bila user memberi link edit biasa, konversi ke export CSV).
        if (preg_match('#docs\.google\.com/spreadsheets/d/([a-zA-Z0-9_-]+)#', $url, $m) && strpos($url, 'output=csv') === false) {
            $gid = '';
            if (preg_match('/[#&?]gid=(\d+)/', $url, $g)) {
                $gid = '&gid=' . $g[1];
            }
            $url = "https://docs.google.com/spreadsheets/d/{$m[1]}/export?format=csv{$gid}";
        }

        $body = $this->fetchUrl($url);

        $rows = $this->parseCsv((string) $body);
        if ($rows === []) {
            return ['main' => []];
        }

        $header = array_shift($rows);
        $header = array_map(static fn ($h) => trim((string) $h), $header);

        $limit = max(0, (int) ($params['limit'] ?? 0));
        $items = [];
        foreach ($rows as $i => $row) {
            if ($limit > 0 && $i >= $limit) {
                break;
            }
            $item = [];
            foreach ($header as $j => $key) {
                $item[$key !== '' ? $key : 'col_' . $j] = isset($row[$j]) ? $this->castValue(trim((string) $row[$j])) : null;
            }
            $items[] = ['json' => $item];
        }

        return ['main' => $items];
    }

    /**
     * Ambil isi CSV dari URL. Protected agar bisa di-stub di test.
     */
    protected function fetchUrl(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $body  = curl_exec($ch);
        $error = curl_error($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($error || $code >= 400) {
            throw new \Exception("Gagal mengambil spreadsheet (HTTP {$code}): {$error}");
        }

        return (string) $body;
    }

    /**
     * Parse teks CSV sederhana (mendukung tanda kutip & koma di dalam sel).
     */
    protected function parseCsv(string $text): array
    {
        $lines = preg_split("/\r\n|\r|\n/", trim($text));
        if ($lines === false || $lines === ['']) {
            return [];
        }

        return array_map(
            static fn ($line) => str_getcsv((string) $line, ',', '"', '\\'),
            array_values(array_filter($lines, static fn ($l) => trim((string) $l) !== ''))
        );
    }

    /**
     * Ubah string angka menjadi angka agar mudah dipakai di ekspresi.
     */
    protected function castValue(string $value)
    {
        if ($value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        return $value;
    }
}
