<?php

namespace App\Services\Workflow;

use CodeIgniter\Database\BaseConnection;

class ExecutionManager
{
    protected $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    public function create(array $workflow, string $triggerType = 'manual'): int
    {
        $this->db->table('executions')->insert([
            'workflow_id'  => $workflow['id'],
            'status'       => 'running',
            'trigger_type' => $triggerType,
            'started_at'   => date('Y-m-d H:i:s'),
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    public function addNodeExecution(int $executionId, array $node): int
    {
        $this->db->table('execution_nodes')->insert([
            'execution_id' => $executionId,
            'node_id'      => $node['node_id'],
            'node_type'    => $node['node_type'],
            'name'         => $node['name'],
            'status'       => 'pending',
            'started_at'   => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    public function updateNodeExecution(int $executionNodeId, string $status, ?array $input = null, ?array $output = null, ?string $error = null): void
    {
        $data = [
            'status'      => $status,
            'finished_at' => date('Y-m-d H:i:s'),
        ];

        if ($input !== null) {
            $data['input_data'] = $this->safeJson($input);
        }
        if ($output !== null) {
            $data['output_data'] = $this->safeJson($output);
        }
        if ($error !== null) {
            $data['error_message'] = mb_substr($error, 0, 60000);
        }

        $this->db->table('execution_nodes')->where('id', $executionNodeId)->update($data);
    }

    public function addError(int $executionId, ?int $executionNodeId, ?string $nodeId, string $message, ?string $trace = null): void
    {
        $this->db->table('execution_errors')->insert([
            'execution_id'      => $executionId,
            'execution_node_id' => $executionNodeId,
            'node_id'           => $nodeId,
            'message'           => mb_substr($message, 0, 60000),
            'trace'             => $trace ? mb_substr($trace, 0, 60000) : null,
            'created_at'        => date('Y-m-d H:i:s'),
        ]);
    }

    public function addLog(int $executionId, ?int $executionNodeId, string $level, string $message, ?array $context = null): void
    {
        $this->db->table('execution_logs')->insert([
            'execution_id'      => $executionId,
            'execution_node_id' => $executionNodeId,
            'level'             => $level,
            'message'           => $message,
            'context'           => $context ? $this->safeJson($context) : null,
            'created_at'        => date('Y-m-d H:i:s'),
        ]);
    }

    public function finish(int $executionId, string $status, $error, int $startTime): void
    {
        $this->db->table('executions')->where('id', $executionId)->update([
            'status'        => $status,
            'finished_at'   => date('Y-m-d H:i:s'),
            'duration'      => max(0, (int) (microtime(true) - $startTime)),
            'error_message' => $error ? mb_substr($error, 0, 60000) : null,
        ]);
    }

    public function getExecution(int $id): ?array
    {
        $row = $this->db->table('executions')->where('id', $id)->get()->getRowArray();

        return $row ?: null;
    }

    public function getExecutionNodes(int $id): array
    {
        return $this->db->table('execution_nodes')
            ->where('execution_id', $id)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function getExecutionErrors(int $id): array
    {
        return $this->db->table('execution_errors')
            ->where('execution_id', $id)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function getExecutionLogs(int $id): array
    {
        return $this->db->table('execution_logs')
            ->where('execution_id', $id)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $builder = $this->db->table('executions e')
            ->select('e.*, w.name as workflow_name, w.workspace_id')
            ->join('workflows w', 'w.id = e.workflow_id', 'left');

        if (! empty($filters['workflow_id'])) {
            $builder->where('e.workflow_id', $filters['workflow_id']);
        }
        if (! empty($filters['status'])) {
            $builder->where('e.status', $filters['status']);
        }
        if (! empty($filters['workspace_id'])) {
            $builder->where('w.workspace_id', $filters['workspace_id']);
        }

        return $builder->orderBy('e.id', 'DESC')->limit($limit, $offset)->get()->getResultArray();
    }

    protected function safeJson(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) > 60000) {
            $json = json_encode(['truncated' => true, 'keys' => array_keys($data)], JSON_UNESCAPED_UNICODE);
        }

        return (string) $json;
    }
}
