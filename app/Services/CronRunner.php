<?php

namespace App\Services;

use App\Services\Workflow\WorkflowEngine;

/**
 * Menjalankan jadwal (schedules) yang jatuh tempo.
 * Dipanggil dari command CLI `spark cron:run` yang dijadwalkan sekali di cPanel.
 */
class CronRunner
{
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /**
     * Satu tick cron. Jalankan semua jadwal yang jatuh tempo, lalu update status.
     *
     * @return array [ran => int, details => array]
     */
    public function tick(?string $asOf = null): array
    {
        $now = new \DateTimeImmutable($asOf ?? 'now', new \DateTimeZone('UTC'));
        $nowDb = $now->format('Y-m-d H:i:s');

        $schedules = $this->db->table('schedules')
            ->where('active', 1)
            ->get()
            ->getResultArray();

        $ran = 0;
        $details = [];
        $engine = new WorkflowEngine();

        foreach ($schedules as $schedule) {
            $cronService = new CronService();
            $due = $schedule['next_run'] === null
                || strtotime((string) $schedule['next_run']) <= $now->getTimestamp();

            if (! $due) {
                continue;
            }

            $timezone = $schedule['timezone'] ?: 'UTC';
            $nextRun  = $cronService->nextRun($schedule['cron'], $nowDb, $timezone);

            // ===== ATOMIC CLAIM =====
            // Klaim jadwal dengan UPDATE bersyarat: hanya proses yang berhasil
            // menggeser next_run (dari nilai lama persis) yang boleh menjalankan
            // workflow. Tick paralel otomatis gagal claim → tanpa eksekusi dobel.
            $claimed = $this->db->table('schedules')
                ->where('id', $schedule['id'])
                ->where('next_run', $schedule['next_run'])
                ->update([
                    'next_run'    => $nextRun,
                    'updated_at'  => $nowDb,
                ]);
            if (! $claimed || $this->db->affectedRows() === 0) {
                continue; // diklaim tick/proses lain.
            }

            $workflow = $this->db->table('workflows')
                ->where('id', $schedule['workflow_id'])
                ->where('active', 1)
                ->get()
                ->getRowArray();

            if (! $workflow) {
                $this->db->table('schedules')->where('id', $schedule['id'])->update([
                    'last_status' => 'skipped:workflow_inactive',
                    'updated_at'  => $nowDb,
                ]);
                continue;
            }

            try {
                $result = $engine->run($workflow, [], 'schedule', [
                    'scheduleData' => [
                        'schedule_id' => $schedule['id'],
                        'cron'        => $schedule['cron'],
                        'timezone'    => $timezone,
                    ],
                ]);

                $status = $result['status'];
                $ran++;
            } catch (\Throwable $e) {
                $status = 'error:' . $e->getMessage();
            }

            $this->db->table('schedules')->where('id', $schedule['id'])->update([
                'next_run'    => $nextRun,
                'last_run'    => $nowDb,
                'last_status' => $status,
                'updated_at'  => $nowDb,
            ]);

            $details[] = [
                'schedule_id' => $schedule['id'],
                'workflow_id' => $schedule['workflow_id'],
                'status'      => $status,
                'next_run'    => $nextRun,
            ];
        }

        // Catat waktu tick terakhir di settings
        $this->setSetting('cron.last_tick', $nowDb);
        $this->setSetting('cron.last_tick_detail', json_encode(['ran' => $ran, 'at' => $nowDb], JSON_UNESCAPED_UNICODE));

        return ['ran' => $ran, 'details' => $details, 'tick_at' => $nowDb];
    }

    protected function setSetting(string $key, string $value): void
    {
        $existing = $this->db->table('settings')->where('key', $key)->get()->getRowArray();

        if ($existing) {
            $this->db->table('settings')->where('key', $key)->update([
                'value'      => $value,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $this->db->table('settings')->insert([
                'key'        => $key,
                'value'      => $value,
                'type'       => 'string',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function getStatus(): array
    {
        $settings = $this->db->table('settings')
            ->whereIn('key', ['cron.last_tick', 'cron.last_tick_detail'])
            ->get()
            ->getResultArray();

        $status = ['last_tick' => null, 'last_tick_detail' => null];

        foreach ($settings as $row) {
            $status[$row['key'] === 'cron.last_tick' ? 'last_tick' : 'last_tick_detail'] = $row['value'];
        }

        return $status;
    }
}
