<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * API key bisa diikat ke satu proyek (workspace) — opsional.
 * Key tanpa workspace tetap berlaku untuk semua proyek milik user.
 */
class AddApiKeyWorkspace extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('api_keys', [
            'workspace_id' => [
                'type'     => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null'     => true,
                'after'    => 'user_id',
            ],
        ]);

        $this->forge->addKey(['workspace_id']);
        $this->forge->processIndexes('api_keys');
    }

    public function down(): void
    {
        $this->forge->dropColumn('api_keys', 'workspace_id');
    }
}
