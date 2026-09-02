<?php

namespace App\Controllers\Api;

use App\Services\CronRunner;
use App\Services\CronService;
use CodeIgniter\HTTP\ResponseInterface;

class ScheduleController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $db = \Config\Database::connect();
        $workspaceId = $this->currentWorkspaceId();

        $schedules = $db->table('schedules s')
            ->select('s.*, w.name as workflow_name')
            ->join('workflows w', 'w.id = s.workflow_id', 'left')
            ->where('w.workspace_id', $workspaceId)
            ->orderBy('s.id', 'DESC')
            ->get()
            ->getResultArray();

        return $this->success($schedules);
    }

    public function create(): ResponseInterface
    {
        $input = $this->input();
        $workflowId = (int) ($input['workflow_id'] ?? 0);

        $denied = $this->requirePermissionOnWorkflow('schedules:write', $workflowId);
        if ($denied) {
            return $denied;
        }

        $cron = trim((string) ($input['cron'] ?? ''));

        $cronService = new CronService();
        if (! $cronService->validate($cron)) {
            return $this->fail('Ekspresi cron tidak valid. Contoh: */5 * * * *');
        }

        $timezone = $input['timezone'] ?? 'UTC';
        $nextRun  = $cronService->nextRun($cron, date('Y-m-d H:i:s'), $timezone);

        $db = \Config\Database::connect();
        $db->table('schedules')->insert([
            'workflow_id' => $workflowId,
            'cron'        => $cron,
            'timezone'    => $timezone,
            'source'      => 'manual',
            'active'      => ! empty($input['active']) ? 1 : 1,
            'next_run'    => $nextRun,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ], true);
        $id = (int) $db->insertID();

        return $this->success(['id' => $id, 'next_run' => $nextRun], 'Jadwal dibuat', 201);
    }

    public function update(int $id): ResponseInterface
    {
        $db = \Config\Database::connect();
        $schedule = $db->table('schedules')->where('id', $id)->get()->getRowArray();
        if (! $schedule) {
            return $this->fail('Jadwal tidak ditemukan.', 404);
        }
        $denied = $this->requirePermissionOnWorkflow('schedules:write', (int) $schedule['workflow_id']);
        if ($denied) {
            return $denied;
        }

        $input = $this->input();
        $data = ['updated_at' => date('Y-m-d H:i:s')];

        if (isset($input['cron'])) {
            $cron = trim((string) $input['cron']);
            if (! (new CronService())->validate($cron)) {
                return $this->fail('Ekspresi cron tidak valid.');
            }
            $data['cron'] = $cron;
            $data['next_run'] = (new CronService())->nextRun($cron, date('Y-m-d H:i:s'), $schedule['timezone']);
        }
        if (isset($input['timezone'])) {
            $data['timezone'] = $input['timezone'];
        }
        if (array_key_exists('active', $input)) {
            $data['active'] = $input['active'] ? 1 : 0;
        }

        $db->table('schedules')->where('id', $id)->update($data);

        return $this->success(['id' => $id], 'Jadwal diperbarui');
    }

    public function delete(int $id): ResponseInterface
    {
        $db = \Config\Database::connect();
        $schedule = $db->table('schedules')->where('id', $id)->get()->getRowArray();
        if (! $schedule) {
            return $this->fail('Jadwal tidak ditemukan.', 404);
        }
        $denied = $this->requirePermissionOnWorkflow('schedules:write', (int) $schedule['workflow_id']);
        if ($denied) {
            return $denied;
        }

        $db->table('schedules')->where('id', $id)->delete();

        return $this->success(null, 'Jadwal dihapus');
    }

    /**
     * Jalankan jadwal sekarang (test).
     */
    public function runNow(int $id): ResponseInterface
    {
        $db = \Config\Database::connect();
        $schedule = $db->table('schedules')->where('id', $id)->get()->getRowArray();
        if (! $schedule) {
            return $this->fail('Jadwal tidak ditemukan.', 404);
        }
        $denied = $this->requirePermissionOnWorkflow('workflows:execute', (int) $schedule['workflow_id']);
        if ($denied) {
            return $denied;
        }

        $workflow = $db->table('workflows')->where('id', $schedule['workflow_id'])->get()->getRowArray();
        if (! $workflow) {
            return $this->fail('Workflow tidak ditemukan.', 404);
        }

        $engine = new \App\Services\Workflow\WorkflowEngine();
        $result = $engine->run($workflow, [], 'schedule', [
            'scheduleData' => [
                'schedule_id' => $schedule['id'],
                'cron'        => $schedule['cron'],
                'timezone'    => $schedule['timezone'],
            ],
        ]);

        unset($result['outputs'], $result['order']);

        $db->table('schedules')->where('id', $id)->update([
            'last_run'    => date('Y-m-d H:i:s'),
            'last_status' => $result['status'],
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        return $this->success($result, 'Jadwal dijalankan');
    }

    /**
     * Status cron: last_tick + daftar jadwal (untuk verifikasi dari dashboard).
     */
    public function status(): ResponseInterface
    {
        $runner = new CronRunner();
        $status = $runner->getStatus();

        $db = \Config\Database::connect();
        $workspaceId = $this->currentWorkspaceId();

        $upcoming = $db->table('schedules s')
            ->select('s.id, s.cron, s.timezone, s.active, s.next_run, s.last_run, s.last_status, w.name as workflow_name')
            ->join('workflows w', 'w.id = s.workflow_id', 'left')
            ->where('w.workspace_id', $workspaceId)
            ->where('s.active', 1)
            ->orderBy('s.next_run', 'ASC')
            ->get()
            ->getResultArray();

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->success([
            'last_tick'          => $status['last_tick'],
            'last_tick_detail'   => $status['last_tick_detail'] ? json_decode($status['last_tick_detail'], true) : null,
            'server_time_utc'    => $now->format('Y-m-d H:i:s'),
            'cron_healthy'       => $status['last_tick'] !== null && (time() - strtotime($status['last_tick'])) < 600,
            'upcoming'           => $upcoming,
        ]);
    }
}
