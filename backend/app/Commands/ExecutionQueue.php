<?php

namespace App\Commands;

use App\Services\Workflow\ExecutionQueueService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Menjalankan semua eksekusi yang mengantri di background.
 *
 * Crontab (sekali setup di cPanel):
 *   * * * * * /usr/local/bin/php /home/USER/.../spark execution:queue >/dev/null 2>&1
 */
class ExecutionQueue extends BaseCommand
{
    protected $group       = 'Workflow';
    protected $name        = 'execution:queue';
    protected $description = 'Memproses antrian eksekusi workflow background.';

    public function run(array $params)
    {
        $service = new ExecutionQueueService();

        $max = (int) CLI::getOption('max') ?: 50;
        $processed = 0;
        $errors = 0;

        while ($processed < $max) {
            $job = $service->claim();
            if (! $job) {
                break;
            }

            $result = $service->process($job);
            $processed++;

            if ($result['status'] === 'error') {
                $errors++;
            }

            CLI::write(
                sprintf('  [queue#%s] exec#%s -> %s', $result['queue_id'], $result['execution_id'], $result['status']),
                $result['status'] === 'error' ? 'red' : 'green'
            );
        }

        CLI::write("Selesai: {$processed} eksekusi diproses ({$errors} error).", $processed > 0 ? 'green' : 'yellow');
    }
}
