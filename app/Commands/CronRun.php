<?php

namespace App\Commands;

use App\Services\CronRunner;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Menjalankan semua jadwal yang jatuh tempo.
 *
 * Crontab (sekali setup di cPanel):
 *   * * * * * /usr/local/bin/php /home/USER/.../spark cron:run >/dev/null 2>&1
 */
class CronRun extends BaseCommand
{
    protected $group       = 'Workflow';
    protected $name        = 'cron:run';
    protected $description = 'Menjalankan workflow terjadwal yang sudah jatuh tempo.';

    public function run(array $params)
    {
        $runner = new CronRunner();
        $result = $runner->tick();

        CLI::write('Cron tick selesai.', 'green');
        CLI::write('Jadwal dijalankan: ' . $result['ran']);

        foreach ($result['details'] as $detail) {
            $status = $detail['status'] === 'success' ? 'green' : 'yellow';
            CLI::write(
                sprintf('  [%s] workflow #%s -> %s (next: %s)', $detail['schedule_id'], $detail['workflow_id'], $detail['status'], $detail['next_run']),
                $status
            );
        }

        if ($result['ran'] === 0) {
            CLI::write('Tidak ada jadwal yang jatuh tempo.', 'yellow');
        }
    }
}
