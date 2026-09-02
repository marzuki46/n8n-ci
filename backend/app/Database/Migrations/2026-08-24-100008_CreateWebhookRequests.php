<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Webhook Inspector: arsip request masuk ke /webhook/* untuk debugging
 * dan replay manual.
 */
class CreateWebhookRequests extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'path' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
            ],
            'method' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
            ],
            'ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'headers_json' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'query_json' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'body_text' => [
                'type' => 'MEDIUMTEXT',
                'null' => true,
            ],
            // Apakah request lolos validasi token & workflow ditemukan.
            'valid' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'note' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
                'null'       => true,
            ],
            'workflow_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'received_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['path', 'received_at']);
        $this->forge->addKey('valid');
        $this->forge->createTable('webhook_requests', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('webhook_requests', true);
    }
}
