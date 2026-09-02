<?php

use App\Nodes\NodeInterface;
use App\Nodes\WorkflowContext;
use App\Services\Workflow\NodeRegistry;
use App\Services\Workflow\WorkflowEngine;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Node test yang gagal N kali pertama lalu sukses.
 * Memakai counter statis untuk menghitung jumlah eksekusi.
 */
final class FlakyTestNode implements NodeInterface
{
    public static int $calls = 0;

    public static int $failuresBeforeSuccess = 0;

    public function getType(): string
    {
        return 'flaky_test';
    }

    public function getName(): string
    {
        return 'Flaky Test';
    }

    public function getCategory(): string
    {
        return 'Test';
    }

    public function getColor(): string
    {
        return '#888888';
    }

    public function getIcon(): string
    {
        return 'bug';
    }

    public function getDescription(): string
    {
        return 'Gagal lalu sukses (untuk test retry).';
    }

    public function getParameters(): array
    {
        return [];
    }

    public function getExamples(): array
    {
        return [];
    }

    public function getExampleOutput(): array
    {
        return [];
    }

    public function getOutputs(): array
    {
        return ['main'];
    }

    public function isTrigger(): bool
    {
        return false;
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        self::$calls++;

        if (self::$calls <= self::$failuresBeforeSuccess) {
            throw new \RuntimeException('Boom flaky');
        }

        return ['main' => [['ok' => true]]];
    }
}

/**
 * @internal
 */
final class WorkflowEngineTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    /**
     * Null = migrasi semua namespace (termasuk App), bukan hanya Tests\Support.
     */
    protected $namespace = null;

    /**
     * Nonaktifkan regress antar-test: down() migrasi ENUM rapuh di SQLite,
     * jadi data dibersihkan manual di tearDown().
     */
    protected $refresh = false;

    /** @var list<int> */
    private array $workflowIds = [];

    /** @var list<int> */
    private array $workspaceIds = [];

    protected function tearDown(): void
    {
        foreach ($this->workflowIds as $id) {
            $this->db->table('workflows')->where('id', $id)->delete();
        }

        foreach ($this->workspaceIds as $id) {
            $this->db->table('workspaces')->where('id', $id)->delete();
        }

        parent::tearDown();
    }

    private function makeWorkspace(): int
    {
        $this->db->table('workspaces')->insert([
            'name'       => 'Test Workspace',
            'slug'       => 'ws-' . uniqid(),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $id = (int) $this->db->insertID();
        $this->workspaceIds[] = $id;

        return $id;
    }

    private function makeWorkflow(int $workspaceId): int
    {
        $this->db->table('workflows')->insert([
            'workspace_id' => $workspaceId,
            'name'         => 'Test Workflow',
            'status'       => 'active',
            'active'       => 1,
            'version'      => 1,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $id = (int) $this->db->insertID();
        $this->workflowIds[] = $id;

        return $id;
    }

    private function addNode(int $workflowId, string $nodeId, string $nodeType, string $name, array $params = []): void
    {
        $this->db->table('workflow_nodes')->insert([
            'workflow_id'     => $workflowId,
            'node_id'         => $nodeId,
            'node_type'       => $nodeType,
            'name'            => $name,
            'position_x'      => 0,
            'position_y'      => 0,
            'parameters_json' => json_encode($params),
            'disabled'        => 0,
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);
    }

    private function addConnection(int $workflowId, string $from, string $output, string $to, string $input = 'main'): void
    {
        $this->db->table('workflow_connections')->insert([
            'workflow_id'     => $workflowId,
            'source_node'     => $from,
            'source_output'   => $output,
            'target_node'     => $to,
            'target_input'    => $input,
            'connection_type' => 'main',
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
    }

    private function nodeStates(int $executionId): array
    {
        $states = [];
        foreach ($this->db->table('execution_nodes')->where('execution_id', $executionId)->get()->getResultArray() as $row) {
            $states[$row['node_id']] = $row;
        }

        return $states;
    }

    public function testRunsWorkflowEndToEnd(): void
    {
        $workspaceId = $this->makeWorkspace();
        $workflowId  = $this->makeWorkflow($workspaceId);

        $this->addNode($workflowId, 'n-manual', 'manual_trigger', 'Mulai', ['payload' => '{"score":90,"name":"Budi"}']);
        $this->addNode($workflowId, 'n-set', 'set', 'Set Label', [
            'assignments' => '[{"field":"greeting","value":"Halo {{$json.name}}"}]',
        ]);
        $this->addNode($workflowId, 'n-if', 'if', 'Cek Skor', [
            'conditions' => '[{"left":"{{$json.score}}","operator":">","right":"80"}]',
        ]);
        $this->addNode($workflowId, 'n-limit', 'limit', 'Batas', ['maxItems' => 1, 'skipItems' => 0]);
        $this->addNode($workflowId, 'n-fail', 'limit', 'Batas False', ['maxItems' => 1, 'skipItems' => 0]);

        $this->addConnection($workflowId, 'n-manual', 'main', 'n-set');
        $this->addConnection($workflowId, 'n-set', 'main', 'n-if');
        $this->addConnection($workflowId, 'n-if', 'true', 'n-limit');
        $this->addConnection($workflowId, 'n-if', 'false', 'n-fail');

        $engine = new WorkflowEngine(null, $this->db);
        $result = $engine->run(['id' => $workflowId], [], 'manual');

        $this->assertSame('success', $result['status']);
        $this->assertSame([
            'n-manual' => 'success',
            'n-set'    => 'success',
            'n-if'     => 'success',
            'n-limit'  => 'success',
            'n-fail'   => 'skipped',
        ], $result['node_states']);

        $this->seeInDatabase('executions', ['workflow_id' => $workflowId, 'status' => 'success']);

        $states = $this->nodeStates($result['execution_id']);
        $this->assertSame('success', $states['n-set']['status']);
        $this->assertStringContainsString('Halo Budi', (string) $states['n-set']['output_data']);
    }

    public function testUnknownNodeTypeRecordsError(): void
    {
        $workspaceId = $this->makeWorkspace();
        $workflowId  = $this->makeWorkflow($workspaceId);

        $this->addNode($workflowId, 'n-manual', 'manual_trigger', 'Mulai');
        $this->addNode($workflowId, 'n-broken', 'unknown_type', 'Rusak');

        $this->addConnection($workflowId, 'n-manual', 'main', 'n-broken');

        $engine = new WorkflowEngine(null, $this->db);
        $result = $engine->run(['id' => $workflowId], [], 'manual');

        $this->assertSame('error', $result['status']);
        $this->assertSame('error', $result['node_states']['n-broken']);

        $this->seeInDatabase('executions', ['workflow_id' => $workflowId, 'status' => 'error']);
        $this->seeInDatabase('execution_errors', [
            'execution_id' => $result['execution_id'],
            'node_id'      => 'n-broken',
        ]);
    }

    public function testStopNodeStopsWorkflow(): void
    {
        $workspaceId = $this->makeWorkspace();
        $workflowId  = $this->makeWorkflow($workspaceId);

        $this->addNode($workflowId, 'n-manual', 'manual_trigger', 'Mulai');
        $this->addNode($workflowId, 'n-stop', 'stop', 'Stop', ['message' => 'Cukup']);

        $this->addConnection($workflowId, 'n-manual', 'main', 'n-stop');

        $engine = new WorkflowEngine(null, $this->db);
        $result = $engine->run(['id' => $workflowId], [], 'manual');

        $this->assertSame('stopped', $result['status']);
        $this->assertSame('success', $result['node_states']['n-stop']);
        $this->seeInDatabase('executions', ['workflow_id' => $workflowId, 'status' => 'stopped']);
    }

    public function testRetrySucceedsAfterFailures(): void
    {
        $workspaceId = $this->makeWorkspace();
        $workflowId  = $this->makeWorkflow($workspaceId);

        $this->addNode($workflowId, 'n-manual', 'manual_trigger', 'Mulai');
        $this->addNode($workflowId, 'n-flaky', 'flaky_test', 'Flaky', [
            'retryCount'      => 3,
            'retryBackoffMs'  => 10,
            'retryMaxDelayMs' => 50,
        ]);

        $this->addConnection($workflowId, 'n-manual', 'main', 'n-flaky');

        FlakyTestNode::$calls = 0;
        FlakyTestNode::$failuresBeforeSuccess = 2;

        $registry = new NodeRegistry();
        $registry->register(new FlakyTestNode());

        $engine = new WorkflowEngine($registry, $this->db);
        $result = $engine->run(['id' => $workflowId], [], 'manual');

        $this->assertSame('success', $result['status']);
        $this->assertSame('success', $result['node_states']['n-flaky']);
        $this->assertSame(3, FlakyTestNode::$calls, 'Harus ada 1 eksekusi + 2 retry (backoff).');
    }

    public function testRetryExhaustedMarksError(): void
    {
        $workspaceId = $this->makeWorkspace();
        $workflowId  = $this->makeWorkflow($workspaceId);

        $this->addNode($workflowId, 'n-manual', 'manual_trigger', 'Mulai');
        $this->addNode($workflowId, 'n-flaky', 'flaky_test', 'Flaky', [
            'retryCount'      => 1,
            'retryBackoffMs'  => 10,
            'retryMaxDelayMs' => 20,
        ]);

        $this->addConnection($workflowId, 'n-manual', 'main', 'n-flaky');

        FlakyTestNode::$calls = 0;
        FlakyTestNode::$failuresBeforeSuccess = 5;

        $registry = new NodeRegistry();
        $registry->register(new FlakyTestNode());

        $engine = new WorkflowEngine($registry, $this->db);
        $result = $engine->run(['id' => $workflowId], [], 'manual');

        $this->assertSame('error', $result['status']);
        $this->assertSame('error', $result['node_states']['n-flaky']);
        $this->assertSame(2, FlakyTestNode::$calls, '1 eksekusi + 1 retry lalu menyerah.');
        $this->seeInDatabase('execution_errors', [
            'execution_id' => $result['execution_id'],
            'node_id'      => 'n-flaky',
        ]);
    }
}
