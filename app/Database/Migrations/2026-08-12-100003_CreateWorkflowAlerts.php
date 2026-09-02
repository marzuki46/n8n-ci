<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Error alert / notifikasi gagal workflow.
 *
 * workflow_alerts: konfigurasi alert per workflow (email penerima, throttle).
 * alert_logs: riwayat notifikasi yang pernah dikirim.
 */
class CreateWorkflowAlerts extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'workflow_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'email_to' => [
                'type'       => 'VARCHAR',
                'constraint' => 512,
                'null'       => true,
                'comment'    => 'Daftar penerima email dipisah koma. Kosong = hanya log.',
            ],
            'enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'throttle_minutes' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 60,
                'comment'    => 'Minimal jeda antar notifikasi (menit).',
            ],
            'last_sent_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('workflow_id');
        $this->forge->addForeignKey('workflow_id', 'workflows', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('workflow_alerts', true);

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'workflow_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'execution_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'alert_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'workflow_error',
            ],
            'message' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'recipient' => [
                'type'       => 'VARCHAR',
                'constraint' => 512,
                'null'       => true,
            ],
            'email_sent' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('workflow_id');
        $this->forge->addForeignKey('workflow_id', 'workflows', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('alert_logs', true);
    }

    public function down()
    {
        $this->forge->dropTable('alert_logs', true);
        $this->forge->dropTable('workflow_alerts', true);
    }
}
