<?php

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;

class WorkflowController extends BaseApiController
{
    use WorkflowPublication;

    public function index(): ResponseInterface
    {
        $db = \Config\Database::connect();
        $workspaceId = $this->currentWorkspaceId();

        $builder = $db->table('workflows');

        // Filter berdasarkan tag: ?tag_id=123
        $tagId = (int) ($this->request->getGet('tag_id') ?? 0);
        if ($tagId > 0) {
            $ids = array_column(
                $db->table('workflow_tags')->select('workflow_id')->where('tag_id', $tagId)->get()->getResultArray(),
                'workflow_id'
            );
            if ($ids === []) {
                return $this->success([]);
            }
            $builder->whereIn('id', $ids);
        }

        $workflows = $builder
            ->where('workspace_id', $workspaceId)
            ->orderBy('updated_at', 'DESC')
            ->get()
            ->getResultArray();

        foreach ($workflows as &$wf) {
            $wf['node_count'] = (int) $db->table('workflow_nodes')->where('workflow_id', $wf['id'])->countAllResults();
            $wf['tags'] = $db->table('workflow_tags wt')
                ->select('t.id, t.name, t.color')
                ->join('tags t', 't.id = wt.tag_id', 'inner')
                ->where('wt.workflow_id', $wf['id'])
                ->get()
                ->getResultArray();
        }

        return $this->success($workflows);
    }

    public function show(int $id): ResponseInterface
    {
        if (! $this->hasAccessToWorkflow($id)) {
            return $this->fail('Tidak punya akses.', 403);
        }

        $db = \Config\Database::connect();
        $workflow = $db->table('workflows')->where('id', $id)->get()->getRowArray();
        if (! $workflow) {
            return $this->fail('Workflow tidak ditemukan.', 404);
        }

        $nodes = $db->table('workflow_nodes')
            ->where('workflow_id', $id)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $connections = $db->table('workflow_connections')
            ->where('workflow_id', $id)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $variables = $db->table('workflow_variables')
            ->where('workflow_id', $id)
            ->get()
            ->getResultArray();

        return $this->success([
            'workflow'    => $workflow,
            'nodes'       => $nodes,
            'connections' => $connections,
            'variables'   => $variables,
        ]);
    }

    public function create(): ResponseInterface
    {
        $workspaceId = $this->currentWorkspaceId();
        $denied = $this->requirePermission('workflows:write', $workspaceId);
        if ($denied) {
            return $denied;
        }

        $input = $this->input();
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $name = 'Workflow baru';
        }

        $db = \Config\Database::connect();
        $workspaceId = $this->currentWorkspaceId();

        $db->table('workflows')->insert([
            'workspace_id' => $workspaceId,
            'name'         => $name,
            'description'  => $input['description'] ?? null,
            'status'       => 'draft',
            'active'       => 0,
            'version'      => 1,
            'settings_json' => null,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ], true);
        $workflowId = (int) $db->insertID();

        return $this->success(['id' => $workflowId], 'Workflow dibuat', 201);
    }

    /**
     * Simpan state editor (node + koneksi + variabel) dan buat snapshot versi.
     */
    public function save(int $id): ResponseInterface
    {
        $denied = $this->requirePermissionOnWorkflow('workflows:write', $id);
        if ($denied) {
            return $denied;
        }

        $input = $this->input();
        $db = \Config\Database::connect();

        $workflow = $db->table('workflows')->where('id', $id)->get()->getRowArray();
        if (! $workflow) {
            return $this->fail('Workflow tidak ditemukan.', 404);
        }

        $name = trim((string) ($input['name'] ?? $workflow['name']));
        $description = $input['description'] ?? $workflow['description'];

        $rawNodes = $input['nodes'] ?? [];
        $rawConnections = $input['connections'] ?? [];

        if (! is_array($rawNodes) || ! is_array($rawConnections)) {
            return $this->fail('Format nodes/connections salah.');
        }

        $this->db()->transBegin();
        try {
        $this->applyGraph($id, $rawNodes, $rawConnections);

        // Variabel
        if (isset($input['variables']) && is_array($input['variables'])) {
            $db->table('workflow_variables')->where('workflow_id', $id)->delete();
            foreach ($input['variables'] as $var) {
                if (empty($var['key'])) {
                    continue;
                }
                $db->table('workflow_variables')->insert([
                    'workflow_id' => $id,
                    'key'         => $var['key'],
                    'value'       => (string) ($var['value'] ?? ''),
                    'type'        => $var['type'] ?? 'string',
                    'created_at'  => date('Y-m-d H:i:s'),
                    'updated_at'  => date('Y-m-d H:i:s'),
                ]);
            }
        }

        $newVersion = (int) $workflow['version'] + 1;
        $active = ! empty($input['active']) ? 1 : 0;

        // ROI: menit kerja manual yang dihemat per run (untuk insight dashboard).
        if (array_key_exists('time_saved_minutes', $input)) {
            $input['time_saved_minutes'] = max(0, min(10000, (int) $input['time_saved_minutes']));
            $db->table('workflows')->where('id', $id)->update([
                'time_saved_minutes' => $input['time_saved_minutes'],
            ]);
        }

        $db->table('workflows')->where('id', $id)->update([
            'name'          => $name,
            'description'   => $description,
            'version'       => $newVersion,
            'status'        => $active ? 'active' : 'draft',
            'active'        => $active,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        $db->table('workflow_versions')->insert([
            'workflow_id'      => $id,
            'version'          => $newVersion,
            'nodes_json'       => json_encode($rawNodes, JSON_UNESCAPED_UNICODE),
            'connections_json' => json_encode($rawConnections, JSON_UNESCAPED_UNICODE),
            'created_at'       => date('Y-m-d H:i:s'),
        ]);

        // Daftarkan ulang endpoint webhook dari node bertipe webhook
        $db->table('webhooks')->where('workflow_id', $id)->delete();

        foreach ($rawNodes as $n) {
            $nodeType = (string) ($n['type'] ?? $n['node_type'] ?? '');
            if (! in_array($nodeType, ['webhook', 'form_trigger'], true)) {
                continue;
            }

            $data   = $n['data'] ?? [];
            $params = $data['parameters'] ?? ($n['parameters'] ?? []);
            if (is_string($params)) {
                $params = json_decode($params, true) ?: [];
            }
            $params = is_array($params) ? $params : [];

            $webhookPath = trim((string) ($params['path'] ?? ''));
            if ($webhookPath === '') {
                continue;
            }

            $method = strtoupper(trim((string) ($params['method'] ?? 'POST'))) ?: 'POST';

            // path+method unik global: endpoint yang terdaftar paling akhir yang menang
            $db->table('webhooks')->where('path', $webhookPath)->where('method', $method)->delete();

            $methods = $nodeType === 'form_trigger' ? ['GET', 'POST'] : [$method];

            foreach ($methods as $m) {
                $db->table('webhooks')->where('path', $webhookPath)->where('method', $m)->delete();

                $db->table('webhooks')->insert([
                    'workflow_id'    => $id,
                    'path'           => $webhookPath,
                    'method'         => $m,
                    'authentication' => ! empty($params['auth_token']) ? 'header' : 'none',
                    'response_mode'  => 'respond',
                    'active'         => $active,
                    'created_at'     => date('Y-m-d H:i:s'),
                    'updated_at'     => date('Y-m-d H:i:s'),
                ]);
            }
        }

        // Daftarkan jadwal otomatis dari node bertipe schedule_trigger
        $db->table('schedules')->where('workflow_id', $id)->where('source', 'node')->delete();

        $cronService = new \App\Services\CronService();

        foreach ($rawNodes as $n) {
            $nodeType = (string) ($n['type'] ?? $n['node_type'] ?? '');
            if ($nodeType !== 'schedule_trigger') {
                continue;
            }

            $data   = $n['data'] ?? [];
            $params = $data['parameters'] ?? ($n['parameters'] ?? []);
            if (is_string($params)) {
                $params = json_decode($params, true) ?: [];
            }
            $params = is_array($params) ? $params : [];

            $cron = trim((string) ($params['cron'] ?? ''));
            if ($cron === '' || ! $cronService->validate($cron)) {
                continue;
            }

            $timezone = trim((string) ($params['timezone'] ?? 'UTC')) ?: 'UTC';
            $nextRun  = $cronService->nextRun($cron, date('Y-m-d H:i:s'), $timezone);

            $db->table('schedules')->insert([
                'workflow_id' => $id,
                'cron'        => $cron,
                'timezone'    => $timezone,
                'source'      => 'node',
                'active'      => $active,
                'next_run'    => $nextRun,
                'created_at'  => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
        }

        $this->db()->transCommit();
        } catch (\Throwable $e) {
            $this->db()->transRollback();
            throw $e;
        }

        return $this->success(['id' => $id, 'version' => $newVersion], 'Workflow disimpan');
    }

    /**
     * GET /api/workflows/:id/versions -> daftar snapshot versi.
     */
    public function versions(int $id): ResponseInterface
    {
        $denied = $this->requirePermissionOnWorkflow('workflows:read', $id);
        if ($denied) {
            return $denied;
        }

        $db = \Config\Database::connect();
        $workflow = $db->table('workflows')->where('id', $id)->get()->getRowArray();
        if (! $workflow) {
            return $this->fail('Workflow tidak ditemukan.', 404);
        }

        $versions = $db->table('workflow_versions')
            ->select('id, version, created_at')
            ->where('workflow_id', $id)
            ->orderBy('version', 'DESC')
            ->limit(100)
            ->get()
            ->getResultArray();

        return $this->success([
            'current_version' => (int) $workflow['version'],
            'versions'        => $versions,
        ]);
    }

    /**
     * POST /api/workflows/:id/versions/:version/restore
     * Kembalikan workflow ke snapshot versi tertentu (append-only: versi baru
     * dicatat, riwayat tidak hilang).
     */
    public function restoreVersion(int $id, int $version): ResponseInterface
    {
        $denied = $this->requirePermissionOnWorkflow('workflows:write', $id);
        if ($denied) {
            return $denied;
        }

        $db = \Config\Database::connect();
        $workflow = $db->table('workflows')->where('id', $id)->get()->getRowArray();
        if (! $workflow) {
            return $this->fail('Workflow tidak ditemukan.', 404);
        }

        $snapshot = $db->table('workflow_versions')
            ->where('workflow_id', $id)
            ->where('version', $version)
            ->get()
            ->getRowArray();
        if (! $snapshot) {
            return $this->fail('Versi tidak ditemukan.', 404);
        }

        $nodes = json_decode((string) $snapshot['nodes_json'], true);
        $connections = json_decode((string) $snapshot['connections_json'], true);
        if (! is_array($nodes) || ! is_array($connections)) {
            return $this->fail('Snapshot versi rusak.');
        }

        $this->db()->transBegin();
        try {
        $this->applyGraph($id, $nodes, $connections);

        $newVersion = (int) $workflow['version'] + 1;

        $db->table('workflows')->where('id', $id)->update([
            'version'    => $newVersion,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $db->table('workflow_versions')->insert([
            'workflow_id'      => $id,
            'version'          => $newVersion,
            'nodes_json'       => json_encode($nodes, JSON_UNESCAPED_UNICODE),
            'connections_json' => json_encode($connections, JSON_UNESCAPED_UNICODE),
            'created_at'       => date('Y-m-d H:i:s'),
        ]);

        $this->db()->transCommit();
        } catch (\Throwable $e) {
            $this->db()->transRollback();
            throw $e;
        }

        return $this->success(
            ['id' => $id, 'version' => $newVersion, 'restored_from' => (int) $version],
            "Workflow dikembalikan ke versi {$version}"
        );
    }

    public function update(int $id): ResponseInterface
    {
        $denied = $this->requirePermissionOnWorkflow('workflows:write', $id);
        if ($denied) {
            return $denied;
        }

        $input = $this->input();
        $db = \Config\Database::connect();
        $data = ['updated_at' => date('Y-m-d H:i:s')];

        if (isset($input['name'])) {
            $data['name'] = $input['name'];
        }
        if (array_key_exists('description', $input)) {
            $data['description'] = $input['description'];
        }
        if (array_key_exists('active', $input)) {
            $data['active'] = $input['active'] ? 1 : 0;
            if ($data['active']) {
                $data['status'] = 'active';
            }
        }
        if (array_key_exists('status', $input)) {
            $data['status'] = $input['status'];
        }

        $db->table('workflows')->where('id', $id)->update($data);

        if (array_key_exists('active', $data)) {
            $db->table('webhooks')->where('workflow_id', $id)->update(['active' => $data['active']]);
            $db->table('schedules')->where('workflow_id', $id)->where('source', 'node')->update(['active' => $data['active']]);
        }

        return $this->success(['id' => $id], 'Workflow diperbarui');
    }

    public function delete(int $id): ResponseInterface
    {
        $denied = $this->requirePermissionOnWorkflow('workflows:delete', $id);
        if ($denied) {
            return $denied;
        }

        $db = \Config\Database::connect();
        $db->table('workflow_tags')->where('workflow_id', $id)->delete();
        $db->table('workflow_versions')->where('workflow_id', $id)->delete();
        $db->table('workflow_nodes')->where('workflow_id', $id)->delete();
        $db->table('workflow_connections')->where('workflow_id', $id)->delete();
        $db->table('workflow_variables')->where('workflow_id', $id)->delete();
        $db->table('webhooks')->where('workflow_id', $id)->delete();
        $db->table('schedules')->where('workflow_id', $id)->delete();
        $db->table('workflows')->where('id', $id)->delete();

        return $this->success(null, 'Workflow dihapus');
    }

    /**
     * Simpan node + koneksi sebuah workflow (tanpa variabel, versi, webhook & schedule).
     */
    protected function applyGraph(int $id, array $rawNodes, array $rawConnections): void
    {
        $db = $this->db();

        $db->table('workflow_nodes')->where('workflow_id', $id)->delete();
        $db->table('workflow_connections')->where('workflow_id', $id)->delete();

        foreach ($rawNodes as $n) {
            $nodeId = (string) ($n['id'] ?? '');
            $nodeType = (string) ($n['type'] ?? $n['node_type'] ?? '');
            if ($nodeId === '' || $nodeType === '') {
                continue;
            }

            $position = $n['position'] ?? [];
            $data = $n['data'] ?? [];
            $params = $data['parameters'] ?? ($n['parameters'] ?? []);

            $db->table('workflow_nodes')->insert([
                'workflow_id'     => $id,
                'node_id'         => $nodeId,
                'node_type'       => $nodeType,
                'name'            => (string) ($n['name'] ?? ($data['name'] ?? $nodeId)),
                'position_x'      => (float) ($position['x'] ?? 0),
                'position_y'      => (float) ($position['y'] ?? 0),
                'parameters_json' => is_string($params) ? $params : json_encode($params, JSON_UNESCAPED_UNICODE),
                'credential_id'   => null,
                'disabled'        => ! empty($n['disabled']) ? 1 : 0,
                'created_at'      => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);
        }

        foreach ($rawConnections as $c) {
            $source = (string) ($c['source'] ?? '');
            $target = (string) ($c['target'] ?? '');
            if ($source === '' || $target === '') {
                continue;
            }

            $db->table('workflow_connections')->insert([
                'workflow_id'     => $id,
                'source_node'     => $source,
                'source_output'   => (string) ($c['sourceHandle'] ?? $c['source_output'] ?? 'main'),
                'target_node'     => $target,
                'target_input'    => (string) ($c['targetHandle'] ?? $c['target_input'] ?? 'main'),
                'connection_type' => 'main',
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function duplicate(int $id): ResponseInterface
    {
        $denied = $this->requirePermissionOnWorkflow('workflows:write', $id);
        if ($denied) {
            return $denied;
        }

        $db = \Config\Database::connect();
        $workflow = $db->table('workflows')->where('id', $id)->get()->getRowArray();
        if (! $workflow) {
            return $this->fail('Workflow tidak ditemukan.', 404);
        }

        $this->db()->transBegin();
        try {
        $db->table('workflows')->insert([
            'workspace_id'  => $workflow['workspace_id'],
            'name'          => $workflow['name'] . ' (copy)',
            'description'   => $workflow['description'],
            'status'        => 'draft',
            'active'        => 0,
            'version'       => 1,
            'settings_json' => $workflow['settings_json'],
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ], true);
        $newId = (int) $db->insertID();

        $nodes = $db->table('workflow_nodes')->where('workflow_id', $id)->get()->getResultArray();
        $connections = $db->table('workflow_connections')->where('workflow_id', $id)->get()->getResultArray();

        foreach ($nodes as $node) {
            unset($node['id']);
            $node['workflow_id'] = $newId;
            $node['created_at']  = date('Y-m-d H:i:s');
            $node['updated_at']  = date('Y-m-d H:i:s');
            $db->table('workflow_nodes')->insert($node);
        }

        foreach ($connections as $conn) {
            unset($conn['id']);
            $conn['workflow_id'] = $newId;
            $conn['created_at']  = date('Y-m-d H:i:s');
            $db->table('workflow_connections')->insert($conn);
        }

        $this->db()->transCommit();
        } catch (\Throwable $e) {
            $this->db()->transRollback();
            throw $e;
        }

        return $this->success(['id' => $newId], 'Workflow diduplikasi', 201);
    }

    /**
     * Export workflow ke format JSON (kompatibel dengan format editor n8n).
     */
    public function export(int $id): ResponseInterface
    {
        $denied = $this->requirePermissionOnWorkflow('workflows:read', $id);
        if ($denied) {
            return $denied;
        }

        $db = \Config\Database::connect();
        $workflow = $db->table('workflows')->where('id', $id)->get()->getRowArray();
        if (! $workflow) {
            return $this->fail('Workflow tidak ditemukan.', 404);
        }

        $nodes = $db->table('workflow_nodes')
            ->where('workflow_id', $id)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $connections = $db->table('workflow_connections')
            ->where('workflow_id', $id)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $variables = $db->table('workflow_variables')
            ->where('workflow_id', $id)
            ->get()
            ->getResultArray();

        $exportNodes = [];
        foreach ($nodes as $node) {
            $exportNodes[] = [
                'id'       => $node['node_id'],
                'name'     => $node['name'],
                'type'     => $node['node_type'],
                'position' => ['x' => (float) $node['position_x'], 'y' => (float) $node['position_y']],
                'disabled' => (bool) $node['disabled'],
                'data'     => [
                    'name'       => $node['name'],
                    'parameters' => json_decode($node['parameters_json'] ?: '{}', true) ?: new \stdClass(),
                ],
            ];
        }

        $exportConnections = [];
        foreach ($connections as $c) {
            $exportConnections[] = [
                'source'       => $c['source_node'],
                'sourceHandle' => $c['source_output'],
                'target'       => $c['target_node'],
                'targetHandle' => $c['target_input'],
            ];
        }

        $exportVariables = [];
        foreach ($variables as $var) {
            $exportVariables[] = [
                'key'   => $var['key'],
                'value' => $var['value'],
                'type'  => $var['type'],
            ];
        }

        return $this->success([
            'name'        => $workflow['name'],
            'description' => $workflow['description'],
            'version'     => (int) $workflow['version'],
            'settings'    => json_decode($workflow['settings_json'] ?? '{}', true) ?: new \stdClass(),
            'nodes'       => $exportNodes,
            'connections' => $exportConnections,
            'variables'   => $exportVariables,
        ], 'Workflow diexport');
    }

    /**
     * Import workflow dari JSON (format editor kami atau format n8n).
     */
    public function import(): ResponseInterface
    {
        $workspaceId = $this->currentWorkspaceId();
        $denied = $this->requirePermission('workflows:write', $workspaceId);
        if ($denied) {
            return $denied;
        }

        $input = $this->input();
        $payload = $input['workflow'] ?? $input;

        if (! is_array($payload)) {
            $decoded = json_decode($payload, true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                return $this->fail('Payload JSON tidak valid.', 422);
            }
            $payload = $decoded;
        }

        $name = trim((string) ($payload['name'] ?? 'Workflow impor'));
        if ($name === '') {
            $name = 'Workflow impor';
        }

        $rawNodes = $payload['nodes'] ?? [];
        $rawConnections = $payload['connections'] ?? [];
        if (! is_array($rawNodes) || ! is_array($rawConnections)) {
            return $this->fail('Format nodes/connections salah.', 422);
        }

        [$nodes, $connections] = $this->normalizeImportedGraph($rawNodes, $rawConnections);

        $db = \Config\Database::connect();
        $workspaceId = $this->currentWorkspaceId();

        $this->db()->transBegin();
        try {
        $db->table('workflows')->insert([
            'workspace_id'  => $workspaceId,
            'name'          => $name,
            'description'   => $payload['description'] ?? null,
            'status'        => 'draft',
            'active'        => 0,
            'version'       => 1,
            'settings_json' => isset($payload['settings']) && is_array($payload['settings'])
                ? json_encode($payload['settings'], JSON_UNESCAPED_UNICODE) : null,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ], true);
        $newId = (int) $db->insertID();

        $this->applyGraph($newId, $nodes, $connections);

        $db->table('workflow_versions')->insert([
            'workflow_id'      => $newId,
            'version'          => 1,
            'nodes_json'       => json_encode($nodes, JSON_UNESCAPED_UNICODE),
            'connections_json' => json_encode($connections, JSON_UNESCAPED_UNICODE),
            'created_at'       => date('Y-m-d H:i:s'),
        ]);

        $this->db()->transCommit();
        } catch (\Throwable $e) {
            $this->db()->transRollback();
            throw $e;
        }

        return $this->success(['id' => $newId], 'Workflow diimpor', 201);
    }

    /**
     * Terima format node/connection n8n (object per nama node) maupun format
     * editor kami (array of objects), lalu normalisasi ke format editor kami.
     */
    protected function normalizeImportedGraph(array $rawNodes, array $rawConnections): array
    {
        $nodes = [];
        $nameToId = [];

        foreach ($rawNodes as $n) {
            if (! is_array($n)) {
                continue;
            }

            $nodeId = (string) ($n['id'] ?? '');
            $name = (string) ($n['name'] ?? '');
            if ($nodeId === '') {
                $nodeId = $name !== '' ? $name : 'node-' . count($nodes) . '-' . bin2hex(random_bytes(2));
            }
            if ($name !== '') {
                $nameToId[$name] = $nodeId;
            }

            $type = $this->normalizeNodeType((string) ($n['type'] ?? ''));

            $position = $n['position'] ?? [];
            if (is_array($position) && array_keys($position) === [0, 1]) {
                $position = ['x' => $position[0], 'y' => $position[1]];
            }
            $position = is_array($position) ? $position : [];

            $params = $n['parameters'] ?? ($n['data']['parameters'] ?? []);
            if (is_string($params)) {
                $params = json_decode($params, true) ?: [];
            }
            $params = is_array($params) ? $params : [];

            $nodes[] = [
                'id'       => $nodeId,
                'name'     => $name,
                'type'     => $type,
                'position' => $position,
                'disabled' => ! empty($n['disabled']),
                'data'     => ['name' => $name, 'parameters' => $params],
            ];
        }

        $connections = [];

        if (isset($rawConnections[0]) && is_array($rawConnections[0]) && isset($rawConnections[0]['source'])) {
            // Format editor kami
            foreach ($rawConnections as $c) {
                if (! is_array($c)) {
                    continue;
                }
                $connections[] = [
                    'source'       => (string) ($c['source'] ?? ''),
                    'sourceHandle' => (string) ($c['sourceHandle'] ?? $c['source_output'] ?? 'main'),
                    'target'       => (string) ($c['target'] ?? ''),
                    'targetHandle' => (string) ($c['targetHandle'] ?? $c['target_input'] ?? 'main'),
                ];
            }
        } else {
            // Format n8n: { sourceNodeName: { main: [ [{node, type, index}] ] } }
            foreach ($rawConnections as $sourceName => $outputs) {
                if (! is_array($outputs)) {
                    continue;
                }
                foreach ($outputs as $outputKey => $connectionsByOutput) {
                    foreach ($connectionsByOutput as $connList) {
                        foreach ($connList as $conn) {
                            if (! is_array($conn) || empty($conn['node'])) {
                                continue;
                            }
                            $targetName = (string) $conn['node'];
                            $targetId = $nameToId[$targetName] ?? $targetName;
                            $connections[] = [
                                'source'       => $nameToId[(string) $sourceName] ?? (string) $sourceName,
                                'sourceHandle' => (string) $outputKey,
                                'target'       => $targetId,
                                'targetHandle' => (string) ($conn['index'] ?? 'main'),
                            ];
                        }
                    }
                }
            }
        }

        return [$nodes, $connections];
    }

    /**
     * Normalisasi tipe node n8n ("n8n-nodes-base.webhook") ke tipe internal kami.
     */
    protected function normalizeNodeType(string $type): string
    {
        $type = trim($type);

        if (strpos($type, 'n8n-nodes-base.') === 0) {
            $type = substr($type, strlen('n8n-nodes-base.'));
        }

        $map = [
            'webhook'          => 'webhook',
            'formTrigger'      => 'form_trigger',
            'form'             => 'form_trigger',
            'scheduleTrigger'  => 'schedule_trigger',
            'cron'             => 'schedule_trigger',
            'manualTrigger'    => 'manual_trigger',
            'httpRequest'      => 'http_request',
            'http'             => 'http_request',
            'if'               => 'if',
            'switch'           => 'switch',
            'set'              => 'set',
            'merge'            => 'merge',
            'aggregate'        => 'aggregate',
            'limit'            => 'limit',
            'code'             => 'code',
            'executeWorkflow'  => 'execute_workflow',
            'openAi'           => 'openai',
            'slack'            => 'slack',
            'discord'          => 'discord',
            'telegram'         => 'telegram',
            'email'            => 'email',
            'github'           => 'github',
            'mysql'            => 'mysql',
            'postgres'         => 'postgres',
            'notion'           => 'notion',
            'html'             => 'html',
            'wait'             => 'wait',
            'log'              => 'log',
            'filter'           => 'filter',
            'loop'             => 'loop',
            'removeDuplicates' => 'remove_duplicates',
            'sort'             => 'sort',
            'stopAndError'     => 'stop',
        ];

        return $map[$type] ?? $type;
    }

    protected function db()
    {
        return \Config\Database::connect();
    }
}
