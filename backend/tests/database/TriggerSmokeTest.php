<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Test B5: Smoke test jalur trigger publik — webhook, form, dan cron.
 *
 * @internal
 */
final class TriggerSmokeTest extends CIUnitTestCase
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

    private function makeWorkflow(string $path, string $triggerType = 'webhook', array $params = []): int
    {
        $this->db->table('workflows')->insert([
            'workspace_id' => 1,
            'name'         => 'WF ' . $triggerType . ' ' . uniqid(),
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
            'node_id'         => 'n-trigger',
            'node_type'       => $triggerType,
            'name'            => ucfirst($triggerType),
            'position_x'      => 0,
            'position_y'      => 0,
            'parameters_json' => json_encode(array_merge(['path' => $path], $params)),
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
            'parameters_json' => json_encode(['assignments' => '[{"field":"received","value":"1"}]']),
            'disabled'        => 0,
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        $this->db->table('workflow_connections')->insert([
            'workflow_id'     => $wf,
            'source_node'     => 'n-trigger',
            'source_output'   => 'main',
            'target_node'     => 'n-set',
            'target_input'    => 'main',
            'connection_type' => 'main',
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        $methods = $triggerType === 'form_trigger' ? ['GET', 'POST'] : ['POST'];

        foreach ($methods as $m) {
            $this->db->table('webhooks')->insert([
                'workflow_id'    => $wf,
                'path'           => $path,
                'method'         => $m,
                'authentication' => ! empty($params['auth_token']) ? 'header' : 'none',
                'response_mode'  => 'respond',
                'active'         => 1,
                'created_at'     => date('Y-m-d H:i:s'),
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);
        }

        return $wf;
    }

    public function testWebhookRunsWorkflow(): void
    {
        $path = 'wh-' . uniqid();
        $this->makeWorkflow($path, 'webhook');

        $result = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])
            ->withBody(json_encode(['hello' => 'world']))
            ->post("webhook/{$path}");

        $this->assertSame(200, $result->response()->getStatusCode());
        $this->assertMatchesRegularExpression('/"execution_id":[0-9]+/', $result->getBody());
        $this->assertSame('success', json_decode($result->getBody())->data->message ?? json_decode($result->getBody())->message ?? 'success');
    }

    public function testWebhookRejectsInvalidToken(): void
    {
        $path = 'wh-sec-' . uniqid();
        $this->makeWorkflow($path, 'webhook', ['auth_token' => 'rahasia123']);

        $result = $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest',
            'X-Webhook-Token'   => 'salah',
        ])->post("webhook/{$path}");

        $this->assertSame(401, $result->response()->getStatusCode());
    }

    public function testWebhookAcceptsValidToken(): void
    {
        $path = 'wh-sec-' . uniqid();
        $this->makeWorkflow($path, 'webhook', ['auth_token' => 'rahasia123']);

        $result = $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest',
            'X-Webhook-Token'   => 'rahasia123',
        ])->post("webhook/{$path}");

        $this->assertSame(200, $result->response()->getStatusCode());
        $this->assertMatchesRegularExpression('/"execution_id":[0-9]+/', $result->getBody());
    }

    public function testWebhookNotFoundReturns404(): void
    {
        $result = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])
            ->post('webhook/tidak-ada-' . uniqid());

        $this->assertSame(404, $result->response()->getStatusCode());
    }

    public function testFormTriggerRendersForm(): void
    {
        $path = 'form-' . uniqid();
        $this->makeWorkflow($path, 'form_trigger', ['form_title' => 'Kuesioner', 'fields' => '[{"name":"nama","label":"Nama"}]']);

        $result = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])->get("webhook/{$path}");

        $this->assertSame(200, $result->response()->getStatusCode());
        $this->assertStringContainsString('Kuesioner', $result->getBody());
        $this->assertStringContainsString('name="nama"', $result->getBody());
    }

    public function testFormTriggerSubmitRunsWorkflow(): void
    {
        $path = 'form-' . uniqid();
        $this->makeWorkflow($path, 'form_trigger', ['form_title' => 'Kuesioner', 'fields' => '[{"name":"nama","label":"Nama"}]']);
        $result = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])
            ->post("webhook/{$path}", ['nama' => 'Budi']);

        $this->assertSame(200, $result->response()->getStatusCode());
        $this->assertMatchesRegularExpression('/"execution_id":[0-9]+/', $result->getBody());
    }

    public function testScheduleCronRunsWorkflow(): void
    {
        $path = 'sch-' . uniqid();
        $this->makeWorkflow($path, 'schedule_trigger', ['cron' => '*/5 * * * *']);

        $this->db->table('schedules')->insert([
            'workflow_id' => $this->workflowIds[0],
            'cron'        => '*/5 * * * *',
            'timezone'    => 'UTC',
            'source'      => 'node',
            'active'      => 1,
            'next_run'    => date('Y-m-d H:i:s', time() - 60),
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        $engine = new \App\Services\Workflow\WorkflowEngine(null, $this->db);
        $workflow = $this->db->table('workflows')->where('id', $this->workflowIds[0])->get()->getRowArray();
        $result = $engine->run($workflow, [], 'schedule');

        $this->assertSame('success', $result['status']);
        $this->seeInDatabase('executions', ['workflow_id' => $this->workflowIds[0], 'status' => 'success']);
    }
}
