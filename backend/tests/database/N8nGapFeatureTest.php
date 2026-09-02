<?php

use App\Nodes\AiAgentNode;
use App\Services\ApiKeyService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Stub agent: skrip respons LLM tanpa jaringan.
 * $script = daftar respons; item berisi tool_calls atau content final.
 */
final class AgentStubNode extends AiAgentNode
{
    public array $responses = [];
    public array $capturedPayloads = [];
    private int $idx = 0;

    protected function llmPostJson(string $url, array $payload, string $apiKey): string
    {
        $this->capturedPayloads[] = $payload;
        $resp = $this->responses[$this->idx] ?? end($this->responses);
        $this->idx++;

        return json_encode($resp, JSON_UNESCAPED_UNICODE);
    }
}

/**
 * Gap-closing vs n8n: AI Agent (tool-calling + memory), Classifier,
 * Extractor, Publish/Draft, Replay.
 *
 * @internal
 */
final class N8nGapFeatureTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;

    protected $refresh = false;

    private array $featSession = [];

    /** @var list<int> */
    private array $cleanupWf = [];

    /** @var list<int> */
    private array $cleanupExec = [];

    /** @var list<int> */
    private array $cleanupKeys = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupExec as $id) {
            foreach (['execution_nodes', 'execution_errors'] as $t) {
                $this->db->table($t)->where('execution_id', $id)->delete();
            }
            $this->db->table('executions')->where('id', $id)->delete();
        }
        foreach ($this->cleanupWf as $id) {
            $this->db->table('workflow_publications')->where('workflow_id', $id)->delete();
            $this->db->table('workflow_connections')->where('workflow_id', $id)->delete();
            $this->db->table('workflow_nodes')->where('workflow_id', $id)->delete();
            $this->db->table('webhooks')->where('workflow_id', $id)->delete();
            $this->db->table('workflows')->where('id', $id)->delete();
        }
        foreach ($this->cleanupKeys as $id) {
            $this->db->table('api_keys')->where('id', $id)->delete();
        }
        $this->featSession = [];
        parent::tearDown();
    }

    // =================================================================
    // AI Agent: tool-calling loop
    // =================================================================

    public function testAgentCallsWorkflowToolThenAnswers(): void
    {
        $ws = $this->seedSession();

        // Tool workflow kecil: Set node mengembalikan status "OK-777"
        $toolWf = $this->seedTinySetWorkflow('Tool WF ' . uniqid(), 'OK-777');

        $node = new AgentStubNode();
        $node->responses = [
            // Iterasi 1: LLM minta panggil tool
            ['choices' => [[ 'message' => [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'cek_status',
                        'arguments' => '{}',
                    ],
                ]],
            ]]]],
            // Iterasi 2: jawaban final memakai hasil tool
            ['choices' => [[ 'message' => [
                'role' => 'assistant',
                'content' => 'Status terakhir adalah OK-777.',
            ]]]],
        ];

        $ctx  = new \App\Nodes\WorkflowContext(['id' => 0]);
        $ctx->parameters['credential'] = ['api_key' => 'sk-test', 'base_url' => 'http://ai.mock'];
        $out  = $node->execute(
            [['json' => ['tugas' => 'cek']]],
            [
                'model'         => 'test-model',
                'system'        => 'Kamu tester.',
                'prompt'        => 'Cek sekarang',
                'tools'         => json_encode([[
                    'name'        => 'cek_status',
                    'description' => 'Ambil status terkini',
                    'type'        => 'workflow',
                    'workflow_id' => $toolWf,
                ]]),
                'max_iterations' => 5,
            ],
            $ctx
        );

        $d = $out['main'][0]['json'];
        $this->assertSame(2, $d['iterations']);
        $this->assertSame('Status terakhir adalah OK-777.', $d['content']);
        $this->assertCount(1, $d['tool_trace']);
        $this->assertSame('ok', $d['tool_trace'][0]['status']);

        // Payload iterasi-2 harus membawa hasil tool (protokol valid).
        $second = end($node->capturedPayloads);
        $roles = array_column($second['messages'], 'role');
        $this->assertContains('tool', $roles);
    }

    public function testAgentMaxIterationsReached(): void
    {
        $this->seedSession();

        $loopResp = ['choices' => [['message' => [
            'role' => 'assistant', 'content' => '', 'tool_calls' => [[
                'id' => 'c1', 'type' => 'function',
                'function' => ['name' => 'tak_ada', 'arguments' => '{}'],
            ]],
        ]]]];

        $node = new AgentStubNode();
        $node->responses = [$loopResp];

        $ctx = new \App\Nodes\WorkflowContext(['id' => 0]);
        $ctx->parameters['credential'] = ['api_key' => 'sk-test', 'base_url' => 'http://ai.mock'];
        $out = $node->execute(
            [['json' => []]],
            [
                'prompt'        => 'x',
                'tools'         => json_encode([['name' => 'tak_ada', 'description' => '?', 'type' => 'http', 'url' => 'http://127.0.0.1:9/x']]),
                'max_iterations' => 2,
            ],
            $ctx
        );

        $d = $out['main'][0]['json'];
        $this->assertSame(2, $d['iterations']);
        $this->assertStringContainsString('Batas iterasi', $d['content']);
    }

    public function testAgentMemoryPersistsAcrossRuns(): void
    {
        $this->seedSession();

        $key = 'mem-test-' . uniqid();
        try {
            $mk = function () use ($key) {
                $n = new AgentStubNode();
                $n->responses = [['choices' => [['message' => ['role' => 'assistant', 'content' => 'jawaban final']]]]];

                return [$n, new \App\Nodes\WorkflowContext(['id' => 0])];
            };

            [$a, $ctxA] = $mk();
            $ctxA->parameters['credential'] = ['api_key' => 'sk-test', 'base_url' => 'http://ai.mock'];
            $a->execute([['json' => []]], ['prompt' => 'halo', 'memory_key' => $key], $ctxA);
            [$b, $ctxB] = $mk();
            $ctxB->parameters['credential'] = ['api_key' => 'sk-test', 'base_url' => 'http://ai.mock'];
            $b->execute([['json' => []]], ['prompt' => 'lagi', 'memory_key' => $key], $ctxB);

            // Run kedua harus membawa riwayat run pertama.
            $second = end($b->capturedPayloads);
            $contents = array_column($second['messages'], 'content');
            $this->assertContains('halo', $contents);
            $this->assertContains('jawaban final', $contents);
        } finally {
            $this->db->table('ai_memories')->where('memory_key', $key)->delete();
        }
    }

    // =================================================================
    // Classifier & Extractor (stub LLM)
    // =================================================================

    public function testClassifierRoutesAndNormalizesCategory(): void
    {
        $stubClass = new class extends \App\Nodes\TextClassifierNode {
            public string $fake = '{"classification":"BUG","reason":"error"}';
            protected function llmPostJson(string $url, array $payload, string $apiKey): string
            {
                return json_encode(['choices' => [['message' => ['content' => $this->fake]]]]);
            }
        };
        $stubClass->fake = '{"classification":"bug","reason":"menyebut error"}';

        $ctx = new \App\Nodes\WorkflowContext(['id' => 0]);
        $ctx->parameters['credential'] = ['api_key' => 'sk-test', 'base_url' => 'http://ai.mock'];

        $out = $stubClass->execute(
            [['json' => ['text' => 'aplikasi error saat klik simpan']]],
            [
                'text_field' => 'text',
                'categories' => '[{"name":"bug","description":"error"},{"name":"question","description":"tanya"}]',
                'temperature' => 0,
            ],
            $ctx
        );

        $d = $out['main'][0]['json'];
        $this->assertSame('bug', $d['classification']); // dinormalisasi lowercase sesuai kategori
        $this->assertStringContainsString('error', $d['classification_reason']);
        $this->assertSame('aplikasi error saat klik simpan', $d['text']);
    }

    public function testExtractorReturnsStructuredData(): void
    {
        $stub = new class extends \App\Nodes\InfoExtractorNode {
            protected function llmPostJson(string $url, array $payload, string $apiKey): string
            {
                return json_encode(['choices' => [['message' => ['content' => '{"nama":"Budi","email":"budi@mail.com"}']]]]);
            }
        };

        $ctx = new \App\Nodes\WorkflowContext(['id' => 0]);
        $ctx->parameters['credential'] = ['api_key' => 'sk-test', 'base_url' => 'http://ai.mock'];

        $out = $stub->execute(
            [['json' => ['text' => 'saya Budi, email budi@mail.com']]],
            ['text_field' => 'text', 'schema' => '{"nama":"string","email":"string"}'],
            $ctx
        );

        $ex = $out['main'][0]['json']['extracted'];
        $this->assertSame('Budi', $ex['nama']);
        $this->assertSame('budi@mail.com', $ex['email']);
    }

    // =================================================================
    // Publish / Draft + Replay
    // =================================================================

    private function seedSession(): int
    {
        if ($this->featSession !== []) {
            return $this->featSession['workspace_id'];
        }
        $email = 'gap_' . uniqid() . '@local.dev';
        $this->db->table('users')->insert([
            'name' => 'Gap Owner', 'email' => $email,
            'password' => password_hash('x123', PASSWORD_DEFAULT), 'role' => 'owner',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $uid = (int) $this->db->insertID();
        $this->db->table('workspaces')->insert([
            'name' => 'WS Gap ' . uniqid(), 'slug' => 'gap-' . uniqid(),
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $ws = (int) $this->db->insertID();
        $this->db->table('workspace_users')->insert([
            'workspace_id' => $ws, 'user_id' => $uid, 'role' => 'owner',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->featSession = ['user_id' => $uid, 'workspace_id' => $ws];
        return $ws;
    }

    private function seedTinySetWorkflow(string $name, string $value): int
    {
        $ws = $this->seedSession();
        $now = date('Y-m-d H:i:s');
        $this->db->table('workflows')->insert([
            'workspace_id' => $ws, 'name' => $name, 'status' => 'active',
            'active' => 1, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $wf = (int) $this->db->insertID();
        $this->cleanupWf[] = $wf;

        $this->db->table('workflow_nodes')->insert([
            'workflow_id' => $wf, 'node_id' => 's', 'node_type' => 'set', 'name' => 'Set',
            'parameters_json' => json_encode(['assignments' => [['field' => 'status', 'value' => $value]]]),
        ]);

        return $wf;
    }

    private function insertNode(int $wf, string $nid, string $type, string $name, array $params): void
    {
        $this->db->table('workflow_nodes')->insert([
            'workflow_id' => $wf, 'node_id' => $nid, 'node_type' => $type,
            'name' => $name,
            'parameters_json' => json_encode($params, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function connectNodes(int $wf, string $from, string $to): void
    {
        $this->db->table('workflow_connections')->insert([
            'workflow_id' => $wf, 'source_node' => $from, 'source_output' => 'out-1',
            'target_node' => $to, 'target_input' => 'in-1', 'connection_type' => 'main',
        ]);
    }

    public function testDraftChangeNotLiveUntilPublish(): void
    {
        $ws = $this->seedSession();
        $now = date('Y-m-d H:i:s');
        $this->db->table('workflows')->insert([
            'workspace_id' => $ws, 'name' => 'Pub Test ' . uniqid(), 'status' => 'active',
            'active' => 1, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $wf = (int) $this->db->insertID();
        $this->cleanupWf[] = $wf;

        $this->insertNode($wf, 'trig', 'manual_trigger', 'Mulai', ['payload' => ['v' => 'LAMA']]);
        $this->insertNode($wf, 'set', 'set', 'Set', ['assignments' => [['field' => 'hasil', 'value' => '{{$json.v}}']]]);
        $this->connectNodes($wf, 'trig', 'set');

        // Publish versi awal â†’ eksekusi memakai snapshot.
        $csrf = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])
            ->withSession(['user_id' => $this->featSession['user_id'], 'user_role' => 'owner'])
            ->get('api/csrf');
        preg_match('/"token":"([0-9a-f]{32})"/i', $csrf->getBody(), $m);
        $_COOKIE['csrf_cookie_name'] = $m[1] ?? '';
        $h = [
            'X-Requested-With' => 'xmlhttprequest',
            'X-CSRF-TOKEN'     => $m[1] ?? '',
            'Content-Type'     => 'application/json',
        ];
        $pub = $this->withHeaders($h)->post("api/workflows/{$wf}/publish");
        unset($_COOKIE['csrf_cookie_name']);
        $this->assertSame(200, $pub->response()->getStatusCode(), $pub->getBody());

        // Ubah DRAFT: ganti nilai set menjadi BARU (tanpa publish).
        $this->db->table('workflow_nodes')
            ->where('workflow_id', $wf)->where('node_id', 'set')
            ->update(['parameters_json' => json_encode([
                'assignments' => [['field' => 'hasil', 'value' => 'BARU']],
            ])]);

        $_COOKIE['csrf_cookie_name'] = $m[1] ?? '';
        $res1 = $this->withHeaders($h)->post("api/workflows/{$wf}/execute");
        unset($_COOKIE['csrf_cookie_name']);
        $body1 = json_decode((string) $res1->response()->getBody(), true);
        $this->assertNotNull(
            $body1,
            'Execute harus JSON. STATUS=' . $res1->response()->getStatusCode()
            . ' CT=' . $res1->response()->getHeaderLine('Content-Type')
            . ' BODYRESP=' . substr((string) $res1->response()->getBody(), 0, 300)
            . ' BODY=' . substr((string) $res1->response()->getBody(), 0, 300)
        );
        $eid1 = (int) $body1['data']['execution_id'];
        $this->cleanupExec[] = $eid1;

        $det1 = json_decode(
            $this->withHeaders($h)->get("api/executions/{$eid1}")->response()->getBody(),
            true
        );
        $setOut1 = null;
        foreach (($det1['data']['nodes'] ?? []) as $n) {
            if (($n['node_id'] ?? '') === 'set') {
                $out = is_array($n['output_data']) ? $n['output_data'] : json_decode((string) $n['output_data'], true);
                $item0 = $out['main'][0] ?? [];
                $json = $item0['json'] ?? $item0;
                $setOut1 = is_array($json) ? ($json['hasil'] ?? null) : null;
            }
        }
        $this->assertSame(
            'LAMA',
            $setOut1,
            'Sebelum publish, eksekusi tetap pakai graf terpublikasi'
        );

        // Publish ulang â†’ eksekusi berikutnya memakai draft baru.
        $_COOKIE['csrf_cookie_name'] = $m[1] ?? '';
        $this->withHeaders($h)->post("api/workflows/{$wf}/publish");
        unset($_COOKIE['csrf_cookie_name']);

        $_COOKIE['csrf_cookie_name'] = $m[1] ?? '';
        $res2 = $this->withHeaders($h)->post("api/workflows/{$wf}/execute");
        unset($_COOKIE['csrf_cookie_name']);
        $body2 = json_decode((string) $res2->response()->getBody(), true);
        $this->assertNotNull($body2, 'Execute-2 harus JSON: ' . $res2->getContent());
        $eid2 = (int) $body2['data']['execution_id'];
        $this->cleanupExec[] = $eid2;

        $det2 = json_decode(
            $this->withHeaders($h)->get("api/executions/{$eid2}")->response()->getBody(),
            true
        );
        $setOut2 = null;
        foreach (($det2['data']['nodes'] ?? []) as $n) {
            if (($n['node_id'] ?? '') === 'set') {
                $out = is_array($n['output_data']) ? $n['output_data'] : json_decode((string) $n['output_data'], true);
                $item0 = $out['main'][0] ?? [];
                $json = $item0['json'] ?? $item0;
                $setOut2 = is_array($json) ? ($json['hasil'] ?? null) : null;
            }
        }
        $this->assertSame('BARU', $setOut2, 'Setelah publish, graf baru dipakai');
    }

    public function testReplayStartsFromFailedNodeOnly(): void
    {
        $ws = $this->seedSession();
        $now = date('Y-m-d H:i:s');
        $this->db->table('workflows')->insert([
            'workspace_id' => $ws, 'name' => 'Replay Test ' . uniqid(), 'status' => 'active',
            'active' => 1, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $wf = (int) $this->db->insertID();
        $this->cleanupWf[] = $wf;

        $this->insertNode($wf, 'trig', 'manual_trigger', 'Mulai', ['payload' => ['msg' => 'halo']]);
        $this->insertNode($wf, 'setA', 'set', 'Set A', ['assignments' => [['field' => 'f', 'value' => 'ok']]]);
        $this->insertNode($wf, 'boom', 'code', 'Boom', ['code' => "throw new Error('meledak')"]);
        $this->connectNodes($wf, 'trig', 'setA');
        $this->connectNodes($wf, 'setA', 'boom');

        $csrf = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])
            ->withSession(['user_id' => $this->featSession['user_id'], 'user_role' => 'owner'])
            ->get('api/csrf');
        preg_match('/"token":"([0-9a-f]{32})"/i', $csrf->getBody(), $m);
        $_COOKIE['csrf_cookie_name'] = $m[1] ?? '';
        $h = [
            'X-Requested-With' => 'xmlhttprequest',
            'X-CSRF-TOKEN'     => $m[1] ?? '',
            'Content-Type'     => 'application/json',
        ];

        // Eksekusi 1: gagal di boom.
        $r1 = $this->withHeaders($h)->post("api/workflows/{$wf}/execute");
        $b1 = json_decode((string) $r1->response()->getBody(), true);
        $e1 = (int) $b1['data']['execution_id'];
        $this->cleanupExec[] = $e1;
        $this->assertSame('error', $b1['data']['status']);

        // Perbaiki kode node boom langsung di DB (simulasi developer fix).
        $this->db->table('workflow_nodes')
            ->where('workflow_id', $wf)->where('node_id', 'boom')
            ->update(['parameters_json' => json_encode(['code' => "return { ok: true };"])]);

        // Replay otomatis dari node error pertama.
        $_COOKIE['csrf_cookie_name'] = $m[1] ?? '';
        $rp = $this->withHeaders($h)->withBody(json_encode([]))
            ->withBody(json_encode(new stdClass))->post("api/executions/{$e1}/replay");
        unset($_COOKIE['csrf_cookie_name']);

        $this->assertSame(200, $rp->response()->getStatusCode(), (string) $rp->response()->getBody());
        $rb = json_decode((string) $rp->response()->getBody(), true)['data'];
        $this->assertSame('boom', $rb['replayed_from']);
        $this->assertSame('success', $rb['status']);

        // Node hulu (trig/setA) TIDAK ikut dieksekusi ulang.
        $this->assertArrayNotHasKey('trig', $rb['node_states']);
        $this->assertArrayNotHasKey('setA', $rb['node_states']);
        $this->assertSame('success', $rb['node_states']['boom']);
    }
}




