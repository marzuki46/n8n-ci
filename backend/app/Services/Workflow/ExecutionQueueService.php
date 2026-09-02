<?php

namespace App\Services\Workflow;

use App\Services\Workflow\ExecutionManager;
use App\Services\Workflow\WorkflowEngine;
use CodeIgniter\Database\BaseConnection;

/**
 * Antrian eksekusi workflow secara background (C1).
 *
 * Alur:
 *   enqueue()  -> buat baris executions (status "waiting") + baris execution_queue
 *   claim()    -> ambil job queued yang sudah available, tandai processing (locking)
 *   process()  -> jalankan WorkflowEngine, lalu tandai done/error
 *
 * Diproses oleh command CLI: `php spark execution:queue` (crontab).
 */
class ExecutionQueueService
{
    protected $db;

    protected const MAX_ATTEMPTS = 3;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    /**
     * Masukkan eksekusi ke antrian background.
     *
     * @return array [execution_id, queue_id]
     */
    public function enqueue(int $workflowId, string $triggerType = 'manual', array $triggerInput = [], array $context = [], ?string $availableAt = null): array
    {
        $now = date('Y-m-d H:i:s');

        $this->db->table('executions')->insert([
            'workflow_id'  => $workflowId,
            'status'       => 'waiting',
            'trigger_type' => $triggerType,
            'created_at'   => $now,
        ]);
        $executionId = (int) $this->db->insertID();

        $this->db->table('execution_queue')->insert([
            'execution_id'   => $executionId,
            'workflow_id'    => $workflowId,
            'trigger_type'   => $triggerType,
            'trigger_input'  => $triggerInput !== [] ? json_encode($triggerInput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'context'        => $context !== [] ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'status'         => 'queued',
            'attempts'       => 0,
            'available_at'   => $availableAt ?? $now,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);
        $queueId = (int) $this->db->insertID();

        return ['execution_id' => $executionId, 'queue_id' => $queueId];
    }

    /**
     * Ambil satu job queued yang sudah available lalu kunci (status processing).
     * Kunci dipakai untuk mencegah dua worker memproses job yang sama.
     */
    public function claim(): ?array
    {
        $now = date('Y-m-d H:i:s');

        $job = $this->db->table('execution_queue')
            ->where('status', 'queued')
            ->where('available_at <=', $now)
            ->orderBy('id', 'ASC')
            ->get()
            ->getRowArray();

        if (! $job) {
            return null;
        }

        $this->db->table('execution_queue')->where('id', $job['id'])->update([
            'status'    => 'processing',
            'locked_at' => $now,
            'updated_at' => $now,
        ]);

        $job['status'] = 'processing';
        $job['trigger_input'] = $this->decode($job['trigger_input']);
        $job['context']       = $this->decode($job['context']);

        return $job;
    }

    /**
     * Proses satu job yang sudah di-claim. Retry/backoff terbatas untuk
     * kesalahan transient (max MAX_ATTEMPTS, jeda 5 detik antar percobaan).
     *
     * @return array [queue_id, execution_id, status]
     */
    public function process(array $job): array
    {
        $engine = new WorkflowEngine(null, $this->db);
        $executionManager = new ExecutionManager($this->db);

        $workflow = $this->db->table('workflows')->where('id', $job['workflow_id'])->get()->getRowArray();

        $attempt = 0;
        while (true) {
            try {
                if (! $workflow) {
                    throw new \RuntimeException('Workflow tidak ditemukan.');
                }

                // Status eksekusi berubah waiting -> running
                $this->db->table('executions')->where('id', $job['execution_id'])->update(['status' => 'running', 'started_at' => date('Y-m-d H:i:s')]);

                $result = $engine->run($workflow, $job['trigger_input'] ?? [], $job['trigger_type'] ?? 'manual', $job['context'] ?? [], $job['execution_id']);

                $this->finish($job['id'], 'done', $result['status']);

                return ['queue_id' => $job['id'], 'execution_id' => $job['execution_id'], 'status' => $result['status']];
            } catch (\Throwable $e) {
                $attempt++;
                if ($attempt > self::MAX_ATTEMPTS) {
                    $this->fail($job['id'], $e->getMessage());
                    $this->db->table('executions')->where('id', $job['execution_id'])->update([
                        'status'        => 'error',
                        'finished_at'   => date('Y-m-d H:i:s'),
                        'error_message' => mb_substr($e->getMessage(), 0, 60000),
                    ]);

                    return ['queue_id' => $job['id'], 'execution_id' => $job['execution_id'], 'status' => 'error'];
                }
                usleep(500000);
            }
        }
    }

    public function finish(int $queueId, string $status, ?string $note = null): void
    {
        $this->db->table('execution_queue')->where('id', $queueId)->update([
            'status'        => $status,
            'error_message' => $note,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    public function fail(int $queueId, string $message): void
    {
        $this->db->table('execution_queue')->where('id', $queueId)->update([
            'status'        => 'error',
            'error_message' => mb_substr($message, 0, 60000),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    public function pendingCount(): int
    {
        return (int) $this->db->table('execution_queue')->where('status', 'queued')->countAllResults();
    }

    protected function decode(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
