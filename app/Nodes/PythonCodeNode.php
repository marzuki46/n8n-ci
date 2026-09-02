<?php

namespace App\Nodes;

/**
 * Python Code Node — setara CodeNode tapi mengeksekusi Python.
 *
 * Cara kerja: kode user ditulis ke file .py sementara, dieksekusi via
 * binary Python (env PYTHON_BINARY atau dari PATH), input workflow masuk
 * lewat stdin JSON, hasil keluar lewat stdout JSON. Timeout + kill proses.
 *
 * ⚠️ KEAMANAN: berbeda dari CodeNode (sandbox vm JavaScript), eksekusi
 * Python berjalan sebagai proses sistem TANPA isolasi penuh — kode bisa
 * mengakses filesystem/jaringan mesin server. Gunakan hanya untuk
 * self-hosted dengan user terpercaya. Untuk multi-tenant publik,
 * jalankan dalam container terisolasi.
 */
class PythonCodeNode extends AbstractNode
{
    public function getType(): string
    {
        return 'python_code';
    }

    public function getName(): string
    {
        return 'Python Code';
    }

    public function getCategory(): string
    {
        return 'Data';
    }

    public function getColor(): string
    {
        return '#f0a24b';
    }

    public function getIcon(): string
    {
        return 'file-code';
    }

    public function getDescription(): string
    {
        return 'Jalankan kode Python untuk transformasi data kustom. Input: run(items, params). Perlu Python terpasang di server.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'     => 'entryPoint',
                'label'   => 'Mode Eksekusi',
                'type'    => 'select',
                'options' => [
                    ['value' => 'Run Once for All Items', 'label' => 'Sekali untuk semua item'],
                    ['value' => 'Run Once for Each Item', 'label' => 'Sekali per item'],
                ],
                'default' => 'Run Once for All Items',
            ],
            [
                'key'         => 'code',
                'label'       => 'Kode Python',
                'type'        => 'code',
                'required'    => true,
                'placeholder' => 'return {"hasil": len(items)}',
            ],
            ['key' => 'timeout', 'label' => 'Timeout (detik)', 'type' => 'number', 'default' => 30],
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
            'title'  => 'Contoh: hitung total nilai semua item',
            'input'  => ['nama' => 'item', 'nilai' => 10],
            'params' => [
                'entryPoint' => 'Run Once for All Items',
                'code'       => "total = sum((it.get('json', {}).get('nilai', 0)) for it in items)\nreturn {\"total\": total, \"jumlah_item\": len(items)}",
                'timeout'    => 30,
            ],
        ]];
    }

    public function getExampleOutput(): array
    {
        return ['main' => [['json' => ['total' => 10, 'jumlah_item' => 1]]]];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $code = (string) ($params['code'] ?? '');
        if (trim($code) === '') {
            throw new \Exception('Kode Python kosong.');
        }

        $entry   = ($params['entryPoint'] ?? 'Run Once for All Items') === 'Run Once for Each Item'
            ? 'each' : 'all';
        $timeout = max(1, (int) ($params['timeout'] ?? 30));
        $python  = $this->pythonBinary();

        $tempDir    = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $scriptPath = $tempDir . DIRECTORY_SEPARATOR . 'py_runner_' . bin2hex(random_bytes(6)) . '.py';
        $inputPath  = $tempDir . DIRECTORY_SEPARATOR . 'py_in_' . bin2hex(random_bytes(6)) . '.json';
        $outputPath = $tempDir . DIRECTORY_SEPARATOR . 'py_out_' . bin2hex(random_bytes(6)) . '.json';

        try {
            file_put_contents($scriptPath, $this->runnerScript($code, $entry));
            file_put_contents($inputPath, json_encode([
                'items'  => $inputItems,
                'params' => $params,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $cmd = sprintf(
                '%s %s < %s > %s 2>&1',
                escapeshellarg($python),
                escapeshellarg($scriptPath),
                escapeshellarg($inputPath),
                escapeshellarg($outputPath)
            );

            $exitCode = $this->runProcess($cmd, $inputPath, $outputPath, $timeout);
            $stdout   = $this->readOutputFile($outputPath);
            $result   = $this->readOutput($stdout, $exitCode);
        } finally {
            @unlink($scriptPath);
            @unlink($inputPath);
            @unlink($outputPath);
        }

        if (array_key_exists('items', $result) && is_array($result['items'])) {
            return ['main' => $result['items']];
        }

        return ['main' => [['json' => $result]]];
    }

    /**
     * Deteksi binary Python: env PYTHON_BINARY lalu kandidat umum.
     */
    protected function pythonBinary(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $candidates = array_filter([
            env('PYTHON_BINARY'),
            'python',
            'python3',
            'py',
        ]);

        foreach ($candidates as $candidate) {
            $out = [];
            $rc  = 0;
            @exec(escapeshellarg($candidate) . ' --version 2>&1', $out, $rc);
            if ($rc === 0 && isset($out[0]) && preg_match('/Python\s*\d+/i', trim($out[0]))) {
                $cached = $candidate;

                return $cached;
            }
        }

        throw new \Exception(
            'Python tidak ditemukan di server. Pasang Python 3 lalu pastikan `python --version` '
            . 'berjalan, atau set env PYTHON_BINARY=/path/ke/python.'
        );
    }

    /**
     * Susun script runner: wrapper fungsi run() + pemanggilan + output JSON.
     */
    protected function runnerScript(string $userCode, string $entry): string
    {
        $userCode = str_replace(["\r\n", "\r"], "\n", rtrim($userCode));

        // Indentasi kode user menjadi body fungsi run().
        $indented = implode("\n", array_map(
            static fn ($line) => $line === '' ? '' : '    ' . $line,
            explode("\n", $userCode)
        ));

        if ($entry === 'each') {
            $signature = 'def run(item, index, items, params):';
            $invoke    = <<<'PY'

_result = []
for _i, _it in enumerate(items):
    _r = run(_it, _i, items, params)
    if _r is None:
        continue
    if isinstance(_r, list):
        _result.extend(_norm(_x) for _x in _r)
    else:
        _result.append(_norm(_r))
_result_items = _result
PY;
        } else {
            $signature = 'def run(items, params):';
            $invoke    = <<<'PY'

_r = run(items, params)
if isinstance(_r, list):
    _result_items = [_norm(_x) for _x in _r]
elif _r is None:
    _result_items = []
else:
    _result_items = [_norm(_r)]
PY;
        }

        return <<<PY
import json, sys

data = json.load(sys.stdin)
items = data.get('items', [])
params = data.get('params', {})

def _norm(r):
    if isinstance(r, dict) and 'json' in r and len(r) == 1:
        return r
    if isinstance(r, dict):
        return {'json': r}
    return {'json': r}

{$signature}
{$indented}
{$invoke}

sys.stdout.write(json.dumps({'items': _result_items}, ensure_ascii=False))
PY;
    }

    protected function readOutput(string $stdout, int $exitCode): array
    {
        $decoded = json_decode($stdout, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $errorText = trim($stdout);
            if ($errorText === '') {
                $errorText = 'exit code ' . $exitCode;
            }
            throw new \Exception('Kode Python gagal: ' . mb_substr($errorText, 0, 2000));
        }

        return is_array($decoded) ? $decoded : [];
    }

    protected function runProcess(string $cmd, string $inputPath, string $outputPath, int $timeout): int
    {
        $proc = proc_open($cmd, [], $pipes);

        if (! is_resource($proc)) {
            throw new \Exception('Gagal memulai proses Python.');
        }

        $start = microtime(true);

        while (true) {
            $status = proc_get_status($proc);
            if (! $status['running']) {
                return proc_close($proc);
            }

            if ((microtime(true) - $start) > $timeout) {
                $this->killProcess($proc, $status['pid']);
                proc_close($proc);

                throw new \Exception('Eksekusi kode Python melebihi batas waktu ' . $timeout . ' detik.');
            }

            usleep(50000);
        }
    }

    protected function readOutputFile(string $outputPath): string
    {
        $deadline = microtime(true) + 2.0;

        while (true) {
            $raw = @file_get_contents($outputPath);
            if ($raw !== false) {
                return $raw;
            }
            if (microtime(true) > $deadline) {
                break;
            }
            usleep(30000);
        }

        return '';
    }

    protected function killProcess($proc, int $pid): void
    {
        if (stripos(PHP_OS, 'WIN') === 0) {
            @exec('taskkill /F /T /PID ' . $pid . ' 2>NUL');
        } else {
            @exec('kill -9 ' . $pid);
        }
        @proc_terminate($proc);
    }
}
