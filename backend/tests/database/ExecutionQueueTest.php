<?php

use App\Services\Workflow\ExecutionQueueService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Test C1: Antrian eksekusi background (status waiting + CLI runner).
 *
 * @internal
 */
final class ExecutionQueueTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;

    protected $refresh = false;

    /** @var list<int> */
    private array $workflowIds = [];

    protected function tearDown(): void
    {
        foreach ($this->workflowIds as $id) {
            $this->db->table('executions')->where('workflow_id', $id)->delete();
            $this->db->table('webhooks')->where('workflow_id', $id)->delete();
            $this->db->table('schedules')->where('workflow_id', $id)->delete();
            $this->db->table('workflows')->where('id', $id)->delete();
        }

        parent::tearDown();
    }

    private function makeWorkflow(): int
    {
        $this->db->table('workflows')->insert([
            'workspace_id' => 1,
            'name'         => 'WF Queue ' . uniqid(),
            'status'       => 'active',
            'active'       => 1,
            'version'      => 1,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        $wf = (int) $this->db->insertID();
        $this->workflowIds[] = $wf;

        $this->db->table('workflow_nodes')->insert([
            'workflow_id'     => $wf,
            'node_id'         => 'n-manual',
            'node_type'       => 'manual_trigger',
            'name'            => 'Mulai',
            'position_x'      => 0,
            'position_y'      => 0,
            'parameters_json' => json_encode([]),
            'disabled'        => 0,
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        $this->db->table('workflow_nodes')->insert([
            'workflow_id'     => $wf,
            'node_id'         => 'n-set',
            'node_type'       => 'set',
            'name'            => 'Set',
            'position_x'      => 200,
            'position_y'      => 0,
            'parameters_json' => json_encode(['assignments' => '[{"field":"ok","value":"{{$json.ping}}"}]']),
            'disabled'        => 0,
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        $this->db->table('workflow_connections')->insert([
            'workflow_id'     => $wf,
            'source_node'     => 'n-manual',
            'source_output'   => 'main',
            'target_node'     => 'n-set',
            'target_input'    => 'main',
            'connection_type' => 'main',
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        return $wf;
    }

    public function testEnqueueCreatesWaitingExecution(): void
    {
        $wf = $this->makeWorkflow();
        $service = new ExecutionQueueService($this->db);

        $queued = $service->enqueue($wf, 'manual', [['ping' => 'pong']]);

        $this->assertGreaterThan(0, $queued['execution_id']);
        $this->assertGreaterThan(0, $queued['queue_id']);

        $execution = $this->db->table('executions')->where('id', $queued['execution_id'])->get()->getRowArray();
        $this->assertSame('waiting', $execution['status']);

        $job = $this->db->table('execution_queue')->where('id', $queued['queue_id'])->get()->getRowArray();
        $this->assertSame('queued', $job['status']);
        $this->assertSame(0, (int) $job['attempts']);
    }

    public function testClaimLocksJob(): void
    {
        $wf = $this->makeWorkflow();
        $service = new ExecutionQueueService($this->db);
        $queued = $service->enqueue($wf, 'manual', [['ping' => 'pong']]);

        $job = $service->claim();

        $this->assertNotNull($job);
        $this->assertSame($queued['queue_id'], (int) $job['id']);
        $this->assertSame('processing', $job['status']);
        $this->assertSame([['ping' => 'pong']], $job['trigger_input']);

        $this->assertSame(0, $service->pendingCount());
    }

    public function testProcessRunsWorkflow(): void
    {
        $wf = $this->makeWorkflow();
        $service = new ExecutionQueueService($this->db);
        $queued = $service->enqueue($wf, 'manual', [['ping' => 'pong']]);

        $job = $service->claim();
        $result = $service->process($job);

        $this->assertSame('success', $result['status']);

        $execution = $this->db->table('executions')->where('id', $queued['execution_id'])->get()->getRowArray();
        $this->assertSame('success', $execution['status']);
        $this->assertNotNull($execution['finished_at']);

        $jobRow = $this->db->table('execution_queue')->where('id', $queued['queue_id'])->get()->getRowArray();
        $this->assertSame('done', $jobRow['status']);
    }

    public function testClaimReturnsNullWhenEmpty(): void
    {
        $service = new ExecutionQueueService($this->db);

        $this->assertNull($service->claim());
    }

    public function testExecuteEndpointWithQueuedFlag(): void
    {
        $wf = $this->makeWorkflow();

        $email = 'q_' . uniqid() . '@local.dev';
        $this->db->table('users')->insert([
            'name'       => 'Queue User',
            'email'      => $email,
            'password'   => password_hash('x123', PASSWORD_DEFAULT),
            'role'       => 'owner',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $uid = (int) $this->db->insertID();

        $this->db->table('workspace_users')->insert([
            'workspace_id' => 1,
            'user_id'      => $uid,
            'role'         => 'owner',
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        $this->withSession([
            'user_id'      => $uid,
            'user_role'    => 'owner',
            'user_name'    => 'Queue User',
            'user_email'   => $email,
            'workspace_id' => 1,
        ]);

        $csrf = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])->get('api/csrf');
        $_COOKIE['csrf_cookie_name'] = $this->extractToken($csrf->getBody());

        $result = $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest',
            'X-CSRF-TOKEN'     => $_COOKIE['csrf_cookie_name'],
        ])->withBodyFormat('json')->post("api/workflows/{$wf}/execute", ['queued' => true, 'payload' => ['ping' => 'pong']]);

        $this->assertSame(202, $result->response()->getStatusCode());
        $this->assertStringContainsString('queued', $result->getBody());

        // Proses antrian lewat CLI
        $service = new ExecutionQueueService($this->db);
        $job = $service->claim();
        $this->assertNotNull($job);
        $res = $service->process($job);
        $this->assertSame('success', $res['status']);
    }

    private function extractToken(string $body): string
    {
        if (preg_match('/"token":"([0-9a-f]{32})"/i', $body, $m) === 1) {
            return $m[1];
        }

        return '';
    }
}
