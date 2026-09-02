<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * RAG foundation (vector store) + ROI time-saved tracking.
 */
class AddAiVectorsAndTimeSaved extends Migration
{
    public function up(): void
    {
        // Vector store untuk embeddings (RAG).
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'workspace_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'namespace' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'source' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
                'null'       => true,
            ],
            'content' => [
                'type' => 'TEXT',
            ],
            'vector' => [
                'type' => 'LONGTEXT',
            ],
            'dims' => [
                'type'       => 'INT',
                'constraint' => 6,
                'default'    => 0,
            ],
            'meta_json' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['workspace_id', 'namespace']);
        $this->forge->createTable('ai_vectors', true);

        // ROI: berapa menit kerja manual yang dihemat tiap run workflow ini.
        $this->forge->addColumn('workflows', [
            'time_saved_minutes' => [
                'type'       => 'SMALLINT',
                'constraint' => 5,
                'default'    => 0,
                'null'       => false,
                'after'      => 'published_at',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('ai_vectors', true);
        $this->forge->dropColumn('workflows', 'time_saved_minutes');
    }
}
