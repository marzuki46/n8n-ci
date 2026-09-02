<?php

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * Template Gallery: workflow siap pakai dari folder /workflow-templates.
 */
class TemplateController extends BaseApiController
{
    private function folder(): string
    {
        return ROOTPATH . 'workflow-templates' . DIRECTORY_SEPARATOR;
    }

    /**
     * GET api/templates — daftar template tersedia.
     */
    public function index(): ResponseInterface
    {
        if (! $this->userId()) {
            return $this->fail('Tidak terautentikasi.', 401);
        }

        $out = [];
        foreach (glob($this->folder() . '*.json') ?: [] as $file) {
            $j = json_decode((string) file_get_contents($file), true);
            if (! is_array($j) || empty($j['slug'])) {
                continue;
            }
            $out[] = [
                'slug'        => $j['slug'],
                'name'        => $j['name'] ?? $j['slug'],
                'description' => $j['description'] ?? '',
                'category'    => $j['category'] ?? 'Umum',
                'node_count'  => count($j['workflow']['nodes'] ?? []),
            ];
        }

        usort($out, static fn ($a, $b) => [$a['category'], $a['name']] <=> [$b['category'], $b['name']]);

        return $this->success($out);
    }

    /**
     * POST api/templates/(:segment)/install — buat workflow baru dari template.
     */
    public function install(string $slug): ResponseInterface
    {
        $workspaceId = $this->currentWorkspaceId();
        $denied = $this->requirePermission('workflows:write', $workspaceId);
        if ($denied) {
            return $denied;
        }

        // Cegah path traversal.
        if (! preg_match('/^[a-z0-9-]+$/', $slug)) {
            return $this->fail('Slug template tidak valid.', 422);
        }

        $file = $this->folder() . $slug . '.json';
        if (! is_file($file)) {
            return $this->fail('Template tidak ditemukan.', 404);
        }

        $tpl = json_decode((string) file_get_contents($file), true);
        if (! is_array($tpl) || empty($tpl['workflow'])) {
            return $this->fail('File template rusak.', 422);
        }

        $wf   = $tpl['workflow'];
        $db   = \Config\Database::connect();
        $now  = date('Y-m-d H:i:s');

        $db->table('workflows')->insert([
            'workspace_id' => $workspaceId,
            'name'         => trim((string) ($wf['name'] ?? ($tpl['name'] ?? 'Workflow Template'))),
            'description'  => $wf['description'] ?? ($tpl['description'] ?? null),
            'status'       => 'active',
            'active'       => 0,
            'version'      => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        $wfId = (int) $db->insertID();

        foreach (($wf['nodes'] ?? []) as $n) {
            if (! is_array($n)) {
                continue;
            }
            $params = $n['parameters'] ?? ($n['data']['parameters'] ?? []);
            if (is_string($params)) {
                $params = json_decode($params, true) ?: [];
            }
            $pos = $n['position'] ?? [0, 0];
            if (is_array($pos) && array_keys($pos) === [0, 1]) {
                $pos = ['x' => $pos[0], 'y' => $pos[1]];
            }

            $db->table('workflow_nodes')->insert([
                'workflow_id'     => $wfId,
                'node_id'         => (string) ($n['id'] ?? ('node-' . bin2hex(random_bytes(3)))),
                'node_type'       => (string) ($n['type'] ?? ''),
                'name'            => (string) ($n['name'] ?? ''),
                'parameters_json' => json_encode(is_array($params) ? $params : [], JSON_UNESCAPED_UNICODE),
                'position_x'      => (int) ($pos['x'] ?? 0),
                'position_y'      => (int) ($pos['y'] ?? 0),
                'disabled'        => 0,
            ]);
        }

        foreach (($wf['connections'] ?? []) as $c) {
            if (! is_array($c)) {
                continue;
            }
            $db->table('workflow_connections')->insert([
                'workflow_id'     => $wfId,
                'source_node'     => (string) ($c['source'] ?? ''),
                'source_output'   => (string) ($c['sourceHandle'] ?? 'out-1'),
                'target_node'     => (string) ($c['target'] ?? ''),
                'target_input'    => (string) ($c['targetHandle'] ?? 'in-1'),
                'connection_type' => 'main',
            ]);
        }

        return $this->success([
            'workflow_id' => $wfId,
            'template'    => $slug,
        ], 'Workflow dibuat dari template', 201);
    }
}
