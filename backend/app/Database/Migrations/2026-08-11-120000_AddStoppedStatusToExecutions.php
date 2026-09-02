<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddStoppedStatusToExecutions extends Migration
{
    public function up()
    {
        $this->forge->modifyColumn('executions', [
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['success', 'error', 'running', 'waiting', 'timeout', 'cancelled', 'stopped'],
                'default'    => 'running',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->modifyColumn('executions', [
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['success', 'error', 'running', 'waiting', 'timeout', 'cancelled'],
                'default'    => 'running',
            ],
        ]);
    }
}
