<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Test B3: Toggle active workflow (mengaktifkan/menonaktifkan, cascade ke webhook & schedule).
 *
 * @internal
 */
final class WorkflowToggleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;

    protected $refresh = false;

    private function seedWorkflowWithHook(): array
    {
        $email = 'tg_' . uniqid() . '@local.dev';
        $this->db->table('users')->insert([
            'name'       => 'TG User',
            'email'      => $email,
            'password'   => password_hash('x123', PASSWORD_DEFAULT),
            'role'       => 'owner',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $uid = (int) $this->db->insertID();

        $this->db->table('workspaces')->insert([
            'name'       => 'TG WS',
            'slug'       => 'ws-' . uniqid(),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $ws = (int) $this->db->insertID();

        $this->db->table('workspace_users')->insert([
            'workspace_id' => $ws,
            'user_id'      => $uid,
            'role'         => 'owner',
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        $this->db->table('workflows')->insert([
            'workspace_id' => $ws,
            'name'         => 'WF TG ' . uniqid(),
            'status'       => 'draft',
            'active'       => 0,
            'version'      => 1,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        $wf = (int) $this->db->insertID();

        $this->db->table('webhooks')->insert([
            'workflow_id'    => $wf,
            'path'           => 'tg-' . uniqid(),
            'method'         => 'POST',
            'authentication' => 'none',
            'response_mode'  => 'respond',
            'active'         => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        $this->db->table('schedules')->insert([
            'workflow_id' => $wf,
            'cron'        => '*/5 * * * *',
            'timezone'    => 'UTC',
            'source'      => 'node',
            'active'      => 0,
            'next_run'    => date('Y-m-d H:i:s', time() + 300),
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        $this->withSession([
            'user_id'      => $uid,
            'user_role'    => 'owner',
            'user_name'    => 'TG User',
            'user_email'   => $email,
            'workspace_id' => $ws,
        ]);

        $csrf = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])->get('api/csrf');
        $_COOKIE['csrf_cookie_name'] = $this->extractToken($csrf->getBody());

        return ['user_id' => $uid, 'workspace_id' => $ws, 'workflow_id' => $wf];
    }

    private function headers(): array
    {
        return [
            'X-Requested-With' => 'xmlhttprequest',
            'X-CSRF-TOKEN'     => $_COOKIE['csrf_cookie_name'] ?? '',
        ];
    }

    public function testToggleActivateWorkflow(): void
    {
        $seed = $this->seedWorkflowWithHook();

        $on = $this->withHeaders($this->headers())->withBodyFormat('json')->put("api/workflows/{$seed['workflow_id']}", ['active' => true]);
        $this->assertSame(200, $on->response()->getStatusCode());

        $wf = $this->db->table('workflows')->where('id', $seed['workflow_id'])->get()->getRowArray();
        $this->assertSame(1, (int) $wf['active']);
        $this->assertSame('active', $wf['status']);

        $webhook = $this->db->table('webhooks')->where('workflow_id', $seed['workflow_id'])->get()->getRowArray();
        $schedule = $this->db->table('schedules')->where('workflow_id', $seed['workflow_id'])->get()->getRowArray();
        $this->assertSame(1, (int) $webhook['active']);
        $this->assertSame(1, (int) $schedule['active']);
    }

    public function testToggleDeactivateWorkflow(): void
    {
        $seed = $this->seedWorkflowWithHook();

        $this->db->table('workflows')->where('id', $seed['workflow_id'])->update(['active' => 1, 'status' => 'active']);
        $this->db->table('webhooks')->where('workflow_id', $seed['workflow_id'])->update(['active' => 1]);
        $this->db->table('schedules')->where('workflow_id', $seed['workflow_id'])->update(['active' => 1]);

        $off = $this->withHeaders($this->headers())->withBodyFormat('json')->put("api/workflows/{$seed['workflow_id']}", ['active' => false]);
        $this->assertSame(200, $off->response()->getStatusCode());

        $wf = $this->db->table('workflows')->where('id', $seed['workflow_id'])->get()->getRowArray();
        $webhook = $this->db->table('webhooks')->where('workflow_id', $seed['workflow_id'])->get()->getRowArray();
        $schedule = $this->db->table('schedules')->where('workflow_id', $seed['workflow_id'])->get()->getRowArray();

        $this->assertSame(0, (int) $wf['active']);
        $this->assertSame(0, (int) $webhook['active']);
        $this->assertSame(0, (int) $schedule['active']);
    }

    private function extractToken(string $body): string
    {
        if (preg_match('/"token":"([0-9a-f]{32})"/i', $body, $m) === 1) {
            return $m[1];
        }

        return '';
    }
}
