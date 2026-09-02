<?php

namespace App\Nodes;

use App\Services\Workflow\WorkflowEngine;

/**
 * Execute Workflow — jalankan workflow lain (sub-workflow) secara sinkron,
 * lalu teruskan data keluaran node terakhir dari sub-workflow tersebut.
 *
 * Sub-workflow dicatat sebagai execution terpisah (trigger_type = subworkflow)
 * sehingga tetap terlihat di riwayat Executions.
 */
class ExecuteWorkflowNode extends AbstractNode
{
    public const MAX_DEPTH = 5;

    public function getType(): string
    {
        return 'execute_workflow';
    }

    public function getName(): string
    {
        return 'Execute Workflow';
    }

    public function getCategory(): string
    {
        return 'Flow';
    }

    public function getColor(): string
    {
        return '#7a29e3';
    }

    public function getIcon(): string
    {
        return 'execute';
    }

    public function getDescription(): string
    {
        return 'Panggil workflow lain dan gunakan hasilnya di alur ini.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'         => 'workflowId',
                'label'       => 'ID Workflow Tujuan',
                'type'        => 'number',
                'required'    => true,
                'placeholder' => 'cth: 2',
                'description' => 'ID workflow yang akan dijalankan (lihat di daftar Workflows).',
            ],
            [
                'key'         => 'passInput',
                'label'       => 'Teruskan Data Masuk',
                'type'        => 'boolean',
                'default'     => true,
                'description' => 'Kirim item yang masuk sebagai data trigger ke sub-workflow.',
            ],
        ];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $workflowId = (int) ($params['workflowId'] ?? 0);
        if ($workflowId <= 0) {
            throw new \Exception('Tentukan ID workflow tujuan pada node Execute Workflow.');
        }

        $currentId = (int) ($context->workflow['id'] ?? 0);
        if ($currentId > 0 && $currentId === $workflowId) {
            throw new \Exception('Node Execute Workflow tidak boleh memanggil workflow-nya sendiri.');
        }

        $depth = (int) ($context->parameters['_subworkflowDepth'] ?? 0);
        if ($depth >= self::MAX_DEPTH) {
            throw new \Exception('Rekursi workflow terlalu dalam (maks ' . self::MAX_DEPTH . ' level).');
        }

        $db = \Config\Database::connect();
        $target = $db->table('workflows')->where('id', $workflowId)->get()->getRowArray();
        if (! $target) {
            throw new \Exception("Workflow tujuan #{$workflowId} tidak ditemukan.");
        }

        $passInput = ! empty($params['passInput']);

        $engine = new WorkflowEngine();
        $result = $engine->run($target, $passInput ? $inputItems : [], 'subworkflow', [
            '_subworkflowDepth' => $depth + 1,
        ]);

        return ['main' => $this->lastOutput($result['outputs'] ?? [], $result['order'] ?? [])];
    }

    /**
     * Ambil data output node terakhir yang menghasilkan item pada sub-workflow.
     */
    protected function lastOutput(array $outputs, array $order): array
    {
        for ($i = count($order) - 1; $i >= 0; $i--) {
            $nodeId = $order[$i];
            if (! isset($outputs[$nodeId])) {
                continue;
            }

            $items = $this->pickItems($outputs[$nodeId]);
            if ($items !== []) {
                return $items;
            }
        }

        return [];
    }

    protected function pickItems(array $nodeOutput): array
    {
        if (isset($nodeOutput['main']) && is_array($nodeOutput['main']) && $nodeOutput['main'] !== []) {
            return $nodeOutput['main'];
        }

        foreach ($nodeOutput as $items) {
            if (is_array($items) && $items !== []) {
                return $items;
            }
        }

        return [];
    }

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: panggil sub-workflow pengerjaan order',
    'input' => 
    array (
      'orderId' => 999,
    ),
    'params' => 
    array (
      'workflowId' => 12,
      'passInput' => true,
    ),
  ),
);
    }

    public function getExampleOutput(): array
    {
        return array (
  'main' => 
  array (
    0 => 
    array (
      'json' => 
      array (
        'orderId' => 999,
        'sub_status' => 'success',
      ),
    ),
  ),
);
    }
}
