<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateExecutions extends Migration
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
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['success', 'error', 'running', 'waiting', 'timeout', 'cancelled'],
                'default'    => 'running',
            ],
            'trigger_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'manual',
            ],
            'started_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'finished_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'duration' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 0,
            ],
            'error_message' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('workflow_id');
        $this->forge->addForeignKey('workflow_id', 'workflows', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('executions', true);

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
            'node_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'node_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['pending', 'running', 'success', 'error', 'skipped'],
                'default'    => 'pending',
            ],
            'started_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'finished_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'input_data' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'output_data' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'error_message' => [
                'type' => 'TEXT',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('execution_id');
        $this->forge->addForeignKey('execution_id', 'executions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('execution_nodes', true);

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
            'execution_node_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'node_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'type' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'data' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('execution_id');
        $this->forge->addForeignKey('execution_id', 'executions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('execution_data', true);

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
            'execution_node_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'node_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'message' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'trace' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('execution_id');
        $this->forge->addForeignKey('execution_id', 'executions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('execution_errors', true);

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
            'execution_node_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'level' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'info',
            ],
            'message' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'context' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('execution_id');
        $this->forge->addForeignKey('execution_id', 'executions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('execution_logs', true);
    }

    public function down()
    {
        $this->forge->dropTable('execution_logs', true);
        $this->forge->dropTable('execution_errors', true);
        $this->forge->dropTable('execution_data', true);
        $this->forge->dropTable('execution_nodes', true);
        $this->forge->dropTable('executions', true);
    }
}
