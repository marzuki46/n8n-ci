<?php

namespace App\Controllers\Api;

use App\Services\Workflow\WorkflowEngine;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Draft vs Publish: editor bebas mengubah draft; eksekusi memakai snapshot.
 */
trait WorkflowPublication
{
    /**
     * POST api/workflows/(:num)/publish — snapshot graf draft → live.
     */
    public function publish(int $id): ResponseInterface
    {
        if (! $this->hasAccessToWorkflow($id)) {
            return $this->fail('Tidak punya akses.', 403);
        }

        $db  = \Config\Database::connect();
        $wf  = $db->table('workflows')->where('id', $id)->get()->getRowArray();
        if (! $wf) {
            return $this->fail('Workflow tidak ditemukan.', 404);
        }

        $nodes = $db->table('workflow_nodes')->where('workflow_id', $id)->orderBy('id', 'ASC')->get()->getResultArray();
        $conns = $db->table('workflow_connections')->where('workflow_id', $id)->get()->getResultArray();

        $graph = json_encode(['nodes' => $nodes, 'connections' => $conns], JSON_UNESCAPED_UNICODE);

        $now = date('Y-m-d H:i:s');
        $existing = $db->table('workflow_publications')->where('workflow_id', $id)->get()->getRowArray();
        $data = [
            'graph_json'   => $graph,
            'published_by' => $this->userId() ?: null,
            'published_at' => $now,
        ];
        if ($existing) {
            $db->table('workflow_publications')->where('workflow_id', $id)->update($data);
        } else {
            $data['workflow_id'] = $id;
            $db->table('workflow_publications')->insert($data);
        }

        $db->table('workflows')->where('id', $id)->update(['published_at' => $now]);

        return $this->success([
            'workflow_id'  => $id,
            'published_at' => $now,
            'node_count'   => count($nodes),
        ], 'Workflow dipublikasikan');
    }

    /**
     * POST api/workflows/(:num)/unpublish — hapus snapshot; kembali ke graf live-draft.
     */
    public function unpublish(int $id): ResponseInterface
    {
        if (! $this->hasAccessToWorkflow($id)) {
            return $this->fail('Tidak punya akses.', 403);
        }

        \Config\Database::connect()
            ->table('workflow_publications')->where('workflow_id', $id)->delete();
        \Config\Database::connect()
            ->table('workflows')->where('id', $id)->update(['published_at' => null]);

        return $this->success(['workflow_id' => $id], 'Publikasi dicabut');
    }

    /**
     * GET api/workflows/(:num)/publication
     */
    public function publicationStatus(int $id): ResponseInterface
    {
        if (! $this->hasAccessToWorkflow($id)) {
            return $this->fail('Tidak punya akses.', 403);
        }

        $db = \Config\Database::connect();
        $pub = $db->table('workflow_publications')
            ->select('published_at, published_by')
            ->where('workflow_id', $id)
            ->get()
            ->getRowArray();

        // Draft dianggap berubah bila updated_at workflow > waktu publish.
        $wf = $db->table('workflows')->select('updated_at, published_at')->where('id', $id)->get()->getRowArray();
        $hasDraftChanges = false;
        if ($wf && ! empty($wf['published_at'])) {
            $hasDraftChanges = strtotime((string) $wf['updated_at']) > strtotime((string) $wf['published_at']);
        }

        return $this->success([
            'published'       => $pub !== null,
            'published_at'    => $pub['published_at'] ?? null,
            'has_draft_changes' => $hasDraftChanges,
        ]);
    }

    /**
     * POST api/executions/(:num)/replay {from_node?: "node_id"}
     * Ulangi eksekusi mulai dari node tertentu (default: node gagal pertama)
     * memakai input tercatat pada node itu.
     */
    public function replayExecution(int $executionId): ResponseInterface
    {
        $manager = new \App\Services\Workflow\ExecutionManager();
        $execution = $manager->getExecution($executionId);
        if (! $execution) {
            return $this->fail('Eksekusi tidak ditemukan.', 404);
        }
        if (! $this->hasAccessToWorkflow((int) $execution['workflow_id'])) {
            return $this->fail('Tidak punya akses.', 403);
        }

        $input = $this->input();
        $fromNode = trim((string) ($input['from_node'] ?? ''));

        $nodes = $manager->getExecutionNodes($executionId);
        if ($nodes === []) {
            return $this->fail('Eksekusi tidak memiliki catatan node.', 400);
        }

        if ($fromNode === '') {
            foreach ($nodes as $n) {
                if (($n['status'] ?? '') === 'error') {
                    $fromNode = (string) $n['node_id'];
                    break;
                }
            }
            if ($fromNode === '') {
                return $this->fail('Tidak ada node error untuk direplay. Sertakan from_node.', 400);
            }
        }

        // Ambil input_data tercatat node tujuan.
        $recordedItems = [];
        foreach ($nodes as $n) {
            if ((string) $n['node_id'] === $fromNode) {
                $in = is_string($n['input_data'])
                    ? json_decode($n['input_data'], true)
                    : ($n['input_data'] ?? []);
                if (is_array($in)) {
                    $keys = array_keys($in);
                    $isList = $keys === array_keys($keys) && (array_values($in) === [] || isset($in[0]));
                    $recordedItems = $isList ? $in : [$in];
                }
                break;
            }
        }

        $workflow = \Config\Database::connect()
            ->table('workflows')->where('id', $execution['workflow_id'])->get()->getRowArray();
        if (! $workflow) {
            return $this->fail('Workflow tidak ditemukan.', 404);
        }

        $engine = new WorkflowEngine();
        $result = $engine->run(
            $workflow,
            [],
            (string) ($execution['trigger_type'] ?? 'manual'),
            [],
            null,
            ['replay_from_node' => $fromNode, 'items' => $recordedItems]
        );
        unset($result['outputs'], $result['order']);

        return $this->success(array_merge($result, ['replayed_from' => $fromNode]), 'Replay selesai');
    }
}
