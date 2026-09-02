<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class DebugRun extends BaseCommand
{
    protected $group = 'Tools';
    protected $name = 'debug:run';
    protected $description = 'Debug sementara: load credential + jalankan workflow 22';

    public function run(array $params)
    {
        $db = \Config\Database::connect();
        $svc = new \App\Services\CredentialService();

        // 0. cek kunci encrypter
        $cfg = config('Encryption');
        CLI::write('cfg->key len = ' . strlen((string) $cfg->key));
        CLI::write('cfg->driver = ' . $cfg->driver . ' cipher=' . $cfg->cipher . ' rawData=' . var_export($cfg->rawData, true));
        CLI::write('env encryption.key = ' . var_export(getenv('encryption.key'), true));

        // 0b. determinisme: encrypt string sama dua kali
        $e = service('encrypter');
        $a = $e->encrypt('hello');
        $b = $e->encrypt('hello');
        CLI::write('deterministic = ' . ($a === $b ? 'yes' : 'no'));
        CLI::write('enc len=' . strlen($a) . ' hex=' . bin2hex(substr($a, 0, 8)));
        $exp = $e->encrypt('{"bot_token":"123456:TEST-SMOKE"}');
        CLI::write('expected len = ' . strlen($exp));
        $enc = $e->encrypt('{"bot_token":"123456:TEST-SMOKE"}');
        CLI::write('base64 len = ' . strlen(base64_encode($enc)));

        // 1. decrypt data credential id 3
        $row = $db->table('credentials')->where('id', 3)->get()->getRowArray();
        if (! $row) {
            CLI::write('credential 3 tidak ada', 'red');

            return;
        }
        $raw = (string) $row['data'];
        CLI::write('data_len=' . strlen($raw));
        try {
            $plain = $e->decrypt($raw);
            CLI::write('decrypt ok: ' . json_encode(json_decode($plain)));
        } catch (\Throwable $ex) {
            CLI::write('decrypt exception: ' . $ex->getMessage(), 'red');
        }
        $dec = $svc->decryptData($raw);
        CLI::write('decryptData result = ' . json_encode($dec));

        // 1b. roundtrip service (format baru base64)
        $svcEnc = $svc->encryptData(['bot_token' => 'ABC123']);
        CLI::write('service encrypt is base64? ' . (preg_match('/^[A-Za-z0-9+\/=]+$/', $svcEnc) ? 'yes' : 'no'));
        CLI::write('service roundtrip = ' . ($svc->decryptData($svcEnc) === ['bot_token' => 'ABC123'] ? 'OK' : 'FAIL'));

        // 1c. backward-compat legacy credential id 2 (base64 JSON)
        $legacy = $db->table('credentials')->where('id', 2)->get()->getRowArray();
        $legacyDec = $svc->decryptData((string) ($legacy['data'] ?? ''));
        CLI::write('legacy id2 decrypt = ' . json_encode($legacyDec));

        // 2. loadForNode
        $loaded = $svc->loadForNode(3);
        CLI::write('loadForNode = ' . ($loaded === null ? 'NULL' : json_encode(array_keys($loaded))));

        // 3. jalankan workflow 22
        $wf = $db->table('workflows')->where('id', 22)->get()->getRowArray();
        $engine = new \App\Services\Workflow\WorkflowEngine();
        $result = $engine->run($wf, [], 'manual');
        CLI::write('status=' . $result['status'] . ' states=' . json_encode($result['node_states']));
    }
}
