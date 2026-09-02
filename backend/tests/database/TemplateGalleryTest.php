<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Template Gallery: list dari folder + install menjadi workflow.
 *
 * @internal
 */
final class TemplateGalleryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;
    protected $refresh = false;

    private array $ctx = [];
    private ?int $installedWf = null;

    protected function tearDown(): void
    {
        if ($this->installedWf) {
            foreach (['workflow_connections', 'workflow_nodes'] as $t) {
                $this->db->table($t)->where('workflow_id', $this->installedWf)->delete();
            }
            $this->db->table('workflows')->where('id', $this->installedWf)->delete();
            $this->installedWf = null;
        }
        if (! empty($this->ctx)) {
            if (! empty($this->ctx['user_id'])) {
                $uid = $this->ctx['user_id'];
                $wids = array_column(
                    $this->db->table('workspace_users')->select('workspace_id')->where('user_id', $uid)->get()->getResultArray(),
                    'workspace_id'
                );
                $this->db->table('workspace_users')->where('user_id', $uid)->delete();
                foreach ($wids as $wid) {
                    $this->db->table('workflows')->where('workspace_id', $wid)->delete();
                }
                $this->db->table('workspaces')->whereIn('id', $wids)->delete();
                $this->db->table('users')->where('id', $uid)->delete();
            }
            $this->ctx = [];
        }
        parent::tearDown();
    }

    private function seedOwner(): array
    {
        if ($this->ctx !== []) {
            return $this->ctx;
        }

        $email = 'tpl_' . uniqid() . '@local.dev';
        $this->db->table('users')->insert([
            'name' => 'Tpl Owner', 'email' => $email,
            'password' => password_hash('x123', PASSWORD_DEFAULT), 'role' => 'owner',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $uid = (int) $this->db->insertID();

        $this->db->table('workspaces')->insert([
            'name' => 'WS Tpl ' . uniqid(), 'slug' => 'tpl-' . uniqid(),
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $ws = (int) $this->db->insertID();

        $this->db->table('workspace_users')->insert([
            'workspace_id' => $ws, 'user_id' => $uid, 'role' => 'owner',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->withSession(['user_id' => $uid, 'user_role' => 'owner', 'workspace_id' => $ws]);
        $this->ctx = ['user_id' => $uid, 'workspace_id' => $ws];

        return $this->ctx;
    }

    public function testListTemplatesFromFolder(): void
    {
        $this->seedOwner();

        $res = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])
            ->get('api/templates');

        $this->assertSame(200, $res->response()->getStatusCode());
        $data = json_decode((string) $res->response()->getBody(), true)['data'] ?? [];

        $this->assertNotEmpty($data, 'Folder template harus berisi file');
        $slugs = array_column($data, 'slug');
        $this->assertContains('seo-on-page-audit', $slugs);
        foreach ($data as $t) {
            $this->assertArrayHasKey('node_count', $t);
        }
    }

    public function testInstallCreatesWorkflowWithNodes(): void
    {
        $this->seedOwner();

        $csrf = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])->get('api/csrf');
        preg_match('/"token":"([0-9a-f]{32})"/i', $csrf->getBody(), $m);
        $_COOKIE['csrf_cookie_name'] = $m[1] ?? '';

        $res = $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest',
            'X-CSRF-TOKEN'     => $m[1] ?? '',
        ])->post('api/templates/seo-robots-txt-validator/install');
        unset($_COOKIE['csrf_cookie_name']);

        $this->assertSame(201, $res->response()->getStatusCode(), $res->response()->getBody());
        $body = json_decode((string) $res->response()->getBody(), true);
        $this->installedWf = (int) $body['data']['workflow_id'];

        $nodes = $this->db->table('workflow_nodes')
            ->where('workflow_id', $this->installedWf)->countAllResults();
        $this->assertSame(3, $nodes, 'Template robots validator punya 3 node');

        // Workflow baru inactive (aman sampai diaktifkan user).
        $wfRow = $this->db->table('workflows')->where('id', $this->installedWf)->get()->getRowArray();
        $this->assertSame(0, (int) $wfRow['active']);
    }

    public function testInstallRejectsBadSlug(): void
    {
        $this->seedOwner();

        $csrf = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])->get('api/csrf');
        preg_match('/"token":"([0-9a-f]{32})"/i', $csrf->getBody(), $m);
        $_COOKIE['csrf_cookie_name'] = $m[1] ?? '';

        $res = $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest',
            'X-CSRF-TOKEN'     => $m[1] ?? '',
        ])->post('api/templates/template-tidak-ada-999/install');
        unset($_COOKIE['csrf_cookie_name']);

        // Slug traversal diblokir router (404); slug valid tapi tak ada file → 404 controller.
        $this->assertContains(
            $res->response()->getStatusCode(),
            [404, 422],
            'Template tidak dikenal harus ditolak'
        );
    }
}
