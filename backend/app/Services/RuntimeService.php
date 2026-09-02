<?php

namespace App\Services;

/**
 * Deteksi status engine/runtime yang dipakai node tertentu
 * (Node.js → CodeNode, Python → PythonCodeNode, dll).
 */
class RuntimeService
{
    /**
     * Semua status runtime + info lingkungan.
     */
    public function statuses(): array
    {
        return [
            'php' => [
                'name'       => 'PHP',
                'required'   => true,
                'found'      => true,
                'version'    => PHP_VERSION,
                'binary'     => PHP_BINARY,
                'engine_for' => 'Seluruh sistem (workflow engine)',
            ],
            'mysql' => $this->mysql(),
            'node' => array_merge(
                $this->detect(
                    [env('NODE_BINARY'), 'node'],
                    '/^v\d+\.\d+/i'
                ),
                [
                    'name'       => 'Node.js',
                    'required'   => false,
                    'engine_for' => 'Code Node (JavaScript)',
                    'env_hint'   => 'NODE_BINARY=/path/ke/node',
                ]
            ),
            'python' => array_merge(
                $this->detect(
                    [env('PYTHON_BINARY'), 'python', 'python3', 'py'],
                    '/Python\s*\d+/i'
                ),
                [
                    'name'       => 'Python',
                    'required'   => false,
                    'engine_for' => 'Python Code Node',
                    'env_hint'   => 'PYTHON_BINARY=/path/ke/python',
                ]
            ),
            'extensions' => $this->extensions(),
        ];
    }

    private function mysql(): array
    {
        try {
            $version = (string) \Config\Database::connect()->query('SELECT VERSION() v')->getRow()->v;

            return [
                'name'       => 'MySQL/MariaDB',
                'required'   => true,
                'found'      => true,
                'version'    => $version,
                'engine_for' => 'Penyimpanan workflow & eksekusi',
            ];
        } catch (\Throwable $e) {
            return [
                'name'     => 'MySQL/MariaDB',
                'required' => true,
                'found'    => false,
                'version'  => null,
                'error'    => $e->getMessage(),
            ];
        }
    }

    /**
     * Deteksi binary via exec(--version). Kandidat pertama yang cocok menang.
     */
    private function detect(array $candidates, string $versionPattern): array
    {
        foreach (array_filter($candidates) as $candidate) {
            $out = [];
            $rc  = 0;
            @exec(escapeshellarg((string) $candidate) . ' --version 2>&1', $out, $rc);
            if ($rc === 0 && isset($out[0]) && preg_match($versionPattern, trim($out[0]))) {
                return [
                    'found'   => true,
                    'binary'  => $candidate,
                    'version' => trim($out[0]),
                ];
            }
        }

        return ['found' => false, 'binary' => null, 'version' => null];
    }

    /**
     * Ekstensi PHP penting.
     */
    private function extensions(): array
    {
        $needed = ['curl', 'openssl', 'mbstring', 'json', 'mysqli', 'intl'];
        $out = [];
        foreach ($needed as $ext) {
            $out[$ext] = extension_loaded($ext);
        }

        return $out;
    }
}
