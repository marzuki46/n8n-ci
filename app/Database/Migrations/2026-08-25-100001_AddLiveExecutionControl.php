<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddLiveExecutionControl extends Migration
{
    public function up()
    {
        $this->forge->addColumn('executions', [
            'control_flag' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => true,
                'default'    => null,
                'after'      => 'status',
            ],
        ]);

        // Tambah 'paused' ke ENUM status (CI4 rebuilds ENUM via raw SQL)
        $this->db->query("ALTER TABLE `executions` MODIFY COLUMN `status` ENUM('success','error','running','waiting','timeout','cancelled','stopped','paused') DEFAULT 'running'");
    }

    public function down()
    {
        $this->db->query("ALTER TABLE `executions` MODIFY COLUMN `status` ENUM('success','error','running','waiting','timeout','cancelled','stopped') DEFAULT 'running'");
        $this->forge->dropColumn('executions', 'control_flag');
    }
}
