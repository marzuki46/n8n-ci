<?php

use App\Nodes\VectorStoreNode;
use App\Nodes\WorkflowContext;
use App\Services\AiVectorService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Stub service: vektor deterministik tanpa jaringan.
 */
final class VecStubService extends AiVectorService
{
    public function embed(array $credential, array $texts, string $model = 'openai/text-embedding-3-small', ?int $workflowId = null): array
    {
        return array_map(static function ($t) {
            return str_contains(strtolower($t), 'kucing') ? [1.0, 0.0] : [0.0, 1.0];
        }, $texts);
    }
}

final class RagRoiFeatureTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;
    protected $refresh = false;

    private ?AiVectorService $svc = null;

    protected function tearDown(): void
    {
        $db = \Config\Database::connect('default');
        $db->table('ai_vectors')->where('namespace', 'rag-test')->delete();
        parent::tearDown();
    }

    private function svc(): AiVectorService
    {
        if ($this->svc === null) {
            // Pakai koneksi dev eksplisit agar stabil di env testing.
            $this->svc = new VecStubService(\Config\Database::connect('default'));
        }
        return $this->svc;
    }

    private function cred(): array
    {
        return ['api_key' => 'sk-test', 'base_url' => 'http://ai.mock'];
    }

    public function testUpsertAndSearchFindsMostSimilar(): void
    {
        $docs = [
            ['content' => 'Cerita tentang kucing oren yang suka main', 'source' => '/kucing'],
            ['content' => 'Resep masakan rendang padang', 'source' => '/rendang'],
            ['content' => 'Tips berkebun sayuran di rumah', 'source' => '/kebun'],
        ];
        $saved = $this->svc()->upsert(
            1,
            'rag-test',
            $docs,
            $this->svc()->embed($this->cred(), array_column($docs, 'content'))
        );
        $this->assertSame(3, $saved);

        [$qv] = $this->svc()->embed($this->cred(), ['halo kucing lucu']);
        $hits = $this->svc()->search(1, 'rag-test', $qv, 2);

        $this->assertNotEmpty($hits);
        $this->assertStringContainsString('kucing', strtolower($hits[0]['content']));
        $this->assertGreaterThan(0.9, $hits[0]['score']);
    }

    public function testDeleteNamespaceClearsVectors(): void
    {
        $docs = [['content' => 'kucing hitam']];
        $this->svc()->upsert(1, 'rag-test', $docs, $this->svc()->embed($this->cred(), ['kucing hitam']));

        $deleted = $this->svc()->deleteNamespace(1, 'rag-test');
        $this->assertGreaterThanOrEqual(1, $deleted);
    }

    public function testCosineSimilarityBasics(): void
    {
        $svc = new AiVectorService();
        $this->assertEqualsWithDelta(1.0, $svc->cosineSimilarity([1, 0], [1, 0]), 0.0001);
        $this->assertEqualsWithDelta(0.0, $svc->cosineSimilarity([1, 0], [0, 1]), 0.0001);
        $this->assertEqualsWithDelta(-1.0, $svc->cosineSimilarity([1, 0], [-1, 0]), 0.0001);
    }

    public function testTimeSavedAggregatesInOverview(): void
    {
        $email = 'roi_' . uniqid() . '@local.dev';
        $this->db->table('users')->insert([
            'name' => 'ROI Owner', 'email' => $email,
            'password' => password_hash('x123', PASSWORD_DEFAULT), 'role' => 'owner',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $uid = (int) $this->db->insertID();

        $now = date('Y-m-d H:i:s');
        $this->db->table('workspaces')->insert([
            'name' => 'WS ROI', 'slug' => 'roi-' . uniqid(),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $ws = (int) $this->db->insertID();
        $this->db->table('workspace_users')->insert([
            'workspace_id' => $ws, 'user_id' => $uid, 'role' => 'owner', 'created_at' => $now,
        ]);

        $this->db->table('workflows')->insert([
            'workspace_id' => $ws, 'name' => 'WF Hemat', 'status' => 'active',
            'active' => 1, 'version' => 1, 'time_saved_minutes' => 10,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $wf = (int) $this->db->insertID();

        for ($i = 0; $i < 3; $i++) {
            $this->db->table('executions')->insert([
                'workflow_id' => $wf, 'status' => 'success', 'trigger_type' => 'manual',
                'started_at' => $now, 'finished_at' => $now, 'duration' => 100,
                'created_at' => $now,
            ]);
        }

        $res = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])
            ->withSession(['user_id' => $uid, 'workspace_id' => $ws])
            ->get('api/dashboard/overview');

        $body = json_decode((string) $res->response()->getBody(), true)['data'] ?? [];
        $this->assertSame(30, (int) ($body['time_saved_minutes'] ?? -1), json_encode($body));
        $this->assertSame(3, (int) ($body['time_saved_runs'] ?? -1));

        $this->db->table('executions')->where('workflow_id', $wf)->delete();
        $this->db->table('workflows')->where('id', $wf)->delete();
        $this->db->table('workspace_users')->where('workspace_id', $ws)->delete();
        $this->db->table('workspaces')->where('id', $ws)->delete();
        $this->db->table('users')->where('id', $uid)->delete();
    }
}
