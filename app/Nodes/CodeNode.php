<?php

namespace App\Nodes;

class CodeNode extends AbstractNode
{
    public function getType(): string
    {
        return 'code';
    }

    public function getName(): string
    {
        return 'Code';
    }

    public function getCategory(): string
    {
        return 'Core';
    }

    public function getColor(): string
    {
        return '#e7a13d';
    }

    public function getIcon(): string
    {
        return 'code';
    }

    public function getDescription(): string
    {
        return 'Jalankan JavaScript (Node.js) untuk transformasi data.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'      => 'code',
                'label'    => 'Kode JavaScript',
                'type'     => 'code',
                'required' => true,
                'placeholder' => "return items.map(it => ({ ...it, hasil: it.nilai * 2 }))",
            ],
            [
                'key'      => 'entryPoint',
                'label'    => 'Entry Point',
                'type'     => 'select',
                'options'  => ['Run Once for All Items', 'Run Once for Each Item'],
                'default'  => 'Run Once for All Items',
            ],
            [
                'key'     => 'timeout',
                'label'   => 'Timeout (detik)',
                'type'    => 'number',
                'default' => 30,
                'description' => 'Batas waktu eksekusi kode, cegah worker menggantung.',
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
        $code = (string) ($params['code'] ?? '');
        if (trim($code) === '') {
            throw new \Exception('Kode JavaScript kosong.');
        }

        $entry = $params['entryPoint'] ?? 'Run Once for All Items';
        $timeout = max(1, (int) ($params['timeout'] ?? 30));
        $node = $this->nodeBinary();

        $tempDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $scriptPath = $tempDir . DIRECTORY_SEPARATOR . 'code_runner_' . bin2hex(random_bytes(6)) . '.js';
        $inputPath  = $tempDir . DIRECTORY_SEPARATOR . 'code_in_' . bin2hex(random_bytes(6)) . '.json';
        $outputPath = $tempDir . DIRECTORY_SEPARATOR . 'code_out_' . bin2hex(random_bytes(6)) . '.json';

        try {
            file_put_contents($scriptPath, $this->runnerScript($code, $entry));
            file_put_contents($inputPath, json_encode([
                'items'  => $inputItems,
                'params' => $params,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $cmd = sprintf(
                '%s %s < %s > %s 2>&1',
                $node,
                escapeshellarg($scriptPath),
                escapeshellarg($inputPath),
                escapeshellarg($outputPath)
            );

            $exitCode = $this->runProcess($cmd, $inputPath, $outputPath, $timeout);
            $stdout = $this->readOutputFile($outputPath);
            $result = $this->readOutput($stdout, $exitCode);
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
     * Cari executable node. Urutan: env NODE_BINARY -> `node` di PATH.
     * Dilempar bila node tidak tersedia, agar frontend tahu kegagalannya
     * jelas (fallback: pesan error, bukan process hang).
     */
    protected function nodeBinary(): string
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        $candidates = [env('NODE_BINARY'), 'node'];
        foreach (array_filter($candidates) as $candidate) {
            $cmd = $candidate . ' --version';
            $out = [];
            $rc = 0;
            @exec($cmd . ' 2>&1', $out, $rc);
            if ($rc === 0 && isset($out[0]) && preg_match('/^v\d+\./', trim($out[0]))) {
                $cached = $candidate;

                return $cached;
            }
        }

        throw new \Exception(
            'Node.js tidak ditemukan. CodeNode membutuhkan Node.js untuk menjalankan kode. '
            . 'Install Node.js atau set variabel NODE_BINARY dengan path node (mis. /usr/local/bin/node).'
        );
    }

    protected function runnerScript(string $userCode, string $entry): string
    {
        $userCode = str_replace(["\r\n", "\r"], "\n", $userCode);

        // Kompilasi lewat vm.Script (host-side), lalu jalankan di konteks
        // sandbox. Kode user tidak boleh mengandung `new Function(...)` /
        // `eval(...)` karena codeGeneration strings dinonaktifkan.
        $fnBody = $entry === 'Run Once for Each Item'
            ? 'async function(item, index, items, params) {' . "\n" . $userCode . "\n" . '}'
            : 'async function(items, params) {' . "\n" . $userCode . "\n" . '}';

        if ($entry === 'Run Once for Each Item') {
            $invoke = <<<JS
    const out = [];
    for (let i = 0; i < items.length; i++) {
      const r = await fn(items[i], i, items, params);
      if (Array.isArray(r)) out.push(...r);
      else if (r !== undefined && r !== null) out.push(typeof r === 'object' ? r : { json: r });
    }
    const result = out;
JS;
        } else {
            $invoke = <<<JS
    const r = await fn(items, params);
    const result = Array.isArray(r) ? r : [r];
JS;
        }

        return <<<JS
const vm = require('vm');

let input = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', c => input += c);
process.stdin.on('end', () => {
  (async () => {
    try {
      const data = JSON.parse(input || '{}');
      const items = data.items || [];
      const params = data.params || {};

      // Daftar putih global yang aman. Tanpa require/process/Buffer/module/
      // globalThis, user tidak bisa baca FS, jaringan, env, atau process.
      // codeGeneration strings=false => eval/new Function di konteks dilarang.
      const safeGlobals = ['JSON','Math','Date','Number','String','Boolean','Array',
        'Object','RegExp','Map','Set','Promise','Error','TypeError','RangeError',
        'SyntaxError','URIError','EvalError','ReferenceError','parseInt','parseFloat',
        'isNaN','isFinite','Infinity','NaN','Symbol','BigInt','ArrayBuffer','DataView',
        'TextEncoder','TextDecoder','Intl','WeakMap','WeakSet','Reflect','Proxy',
        'decodeURI','decodeURIComponent','encodeURI','encodeURIComponent',
        'queueMicrotask','clearTimeout','clearInterval','setTimeout','setInterval'];

      const sandbox = Object.create(null);
      for (const name of safeGlobals) {
        if (globalThis[name] !== undefined) sandbox[name] = globalThis[name];
      }

      vm.createContext(sandbox, { codeGeneration: { strings: false, wasm: false } });

      const fn = new vm.Script('(' + {$this->encodeJs($fnBody)} + ')')
        .runInContext(sandbox, { filename: 'code.js' });

$invoke

      process.stdout.write(JSON.stringify({ items: result }));
    } catch (e) {
      console.error(e && e.stack || e);
      process.exit(1);
    }
  })();
});
JS;
    }

    protected function encodeJs(string $body): string
    {
        return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function readOutput(string $stdout, int $exitCode): array
    {
        $decoded = json_decode($stdout, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $errorText = trim($stdout);
            if ($errorText === '') {
                $errorText = 'exit code ' . $exitCode;
            }
            throw new \Exception('Kode JS gagal: ' . $errorText);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Jalankan proses node secara async dengan timeout. I/O memakai
     * redirection shell (cmd.exe) dengan descriptor diwarisi, untuk
     * menghindari pipe/file blocking di Windows. Bila proses belum
     * selesai hingga batas waktu, pohon proses dimatikan.
     *
     * @return int exit code
     */
    protected function runProcess(string $cmd, string $inputPath, string $outputPath, int $timeout): int
    {
        $proc = proc_open($cmd, [], $pipes);

        if (! is_resource($proc)) {
            throw new \Exception('Gagal memulai proses Node.js.');
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

                throw new \Exception('Eksekusi kode JS melebihi batas waktu ' . $timeout . ' detik.');
            }

            usleep(50000);
        }
    }

    /**
     * Baca file output dengan retry singkat: pada Windows handle file
     * dari child proses baru terbebas sesaat setelah proses berakhir.
     */
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

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: hitung diskon & kategori harga',
    'input' => 
    array (
      'produk' => 'Laptop Pro',
      'harga' => 15000000,
    ),
    'params' => 
    array (
      'code' => 'const diskon = $json.harga > 10000000 ? 0.1 : 0;
return { produk: $json.produk, harga_akhir: Math.round($json.harga * (1 - diskon)) };',
      'timeout' => 30,
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
        'produk' => 'Laptop Pro',
        'harga_akhir' => 13500000,
      ),
    ),
  ),
);
    }
}
