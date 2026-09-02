<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Memori percakapan untuk AI Agent (per memory_key).
 */
class CreateAiMemories extends Migration
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
            'memory_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
            ],
            'role' => [
                'type'       => 'ENUM',
                'constraint' => ['user', 'assistant', 'tool'],
                'default'    => 'user',
            ],
            'content' => [
                'type' => 'TEXT',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['memory_key', 'id']);
        $this->forge->createTable('ai_memories', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('ai_memories', true);
    }
}
