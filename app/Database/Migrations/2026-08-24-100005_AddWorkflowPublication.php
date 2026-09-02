<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Draft vs Publish (ala n8n 2.0): editor mengubah draft bebas; eksekusi
 * (termasuk webhook/schedule) memakai snapshot graf yang sudah dipublish.
 */
class AddWorkflowPublication extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('workflows', [
            'published_at' => [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'version',
            ],
        ]);

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
            // Snapshot graf: {nodes:[...], connections:[...]}
            'graph_json' => [
                'type' => 'LONGTEXT',
            ],
            'published_by' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
            ],
            'published_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['workflow_id']);
        $this->forge->addForeignKey('workflow_id', 'workflows', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('workflow_publications', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('workflow_publications', true);
        $this->forge->dropColumn('workflows', 'published_at');
    }
}
