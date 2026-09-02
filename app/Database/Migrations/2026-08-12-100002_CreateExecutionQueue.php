<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tabel antrian eksekusi background (C1).
 * Status eksekusi "waiting" = antri, lalu diproses oleh command `execution:queue`.
 */
class CreateExecutionQueue extends Migration
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
            'execution_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'workflow_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'trigger_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'manual',
            ],
            'trigger_input' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'context' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['queued', 'processing', 'done', 'error'],
                'default'    => 'queued',
            ],
            'attempts' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 0,
            ],
            'available_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'locked_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'error_message' => [
                'type' => 'TEXT',
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
        $this->forge->addKey(['status', 'available_at']);
        $this->forge->addKey('workflow_id');
        $this->forge->addForeignKey('execution_id', 'executions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('workflow_id', 'workflows', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('execution_queue', true);
    }

    public function down()
    {
        $this->forge->dropTable('execution_queue', true);
    }
}
