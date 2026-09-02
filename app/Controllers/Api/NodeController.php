<?php

namespace App\Controllers\Api;

use App\Services\Workflow\NodeRegistry;
use App\Services\Workflow\WorkflowEngine;
use CodeIgniter\HTTP\ResponseInterface;

class NodeController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $registry = new NodeRegistry();

        return $this->success($registry->toApi());
    }

    /**
     * Paket 2 — tombol "Coba Node".
     * Body JSON: {node_type, parameters, sample_data, workflow_id?}
     * Selalu HTTP 200; hasil di body: {ok:true,output,...} / {ok:false,error}.
     */
    public function test(): ResponseInterface
    {
        $input = $this->input();

        $nodeType = (string) ($input['node_type'] ?? '');
        if ($nodeType === '') {
            return $this->success(['ok' => false, 'error' => 'node_type wajib diisi.']);
        }

        $parameters = $input['parameters'] ?? [];
        if (! is_array($parameters)) {
            $parameters = [];
        }

        $sampleData = $input['sample_data'] ?? [];
        if (! is_array($sampleData)) {
            $sampleData = [];
        }

        // Workflow opsional: untuk scoping credential default per proyek.
        $workflow = null;
        $workflowId = (int) ($input['workflow_id'] ?? 0);
        if ($workflowId > 0) {
            if (! $this->hasAccessToWorkflow($workflowId)) {
                return $this->fail('Tidak punya akses ke workflow ini.', 403);
            }
            $workflow = \Config\Database::connect()
                ->table('workflows')->where('id', $workflowId)->get()->getRowArray();
        }

        $node = [
            'node_id'         => 'test-node',
            'name'            => (string) ($input['name'] ?? 'Coba Node'),
            'node_type'       => $nodeType,
            'parameters_json' => json_encode($parameters, JSON_UNESCAPED_UNICODE),
        ];

        $engine = new WorkflowEngine();
        $result = $engine->testNode($node, $sampleData, $workflow);

        return $this->success($result);
    }
}
