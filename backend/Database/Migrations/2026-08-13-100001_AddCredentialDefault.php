<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 2026-08-13-100001_AddCredentialDefault
 * - Tambah kolom is_default ke tabel credentials
 * - Indeks (workspace_id, credential_type_id, is_default DESC)
 */
class AddCredentialDefault extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('credentials', [
            'is_default' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'after'      => 'credential_id',
            ],
        ]);

        // Indeks untuk pencarian default
        $this->forge->addKey(['workspace_id', 'credential_type_id', 'is_default'], false, true, 'idx_workspace_type_default');

        // Indeks untuk fetch default tunggal
        $this->forge->addKey(['workspace_id', 'credential_type_id'], false, true, 'idx_workspace_type');

        // Indeks pembantu untuk select list yang is_default=1
        $this->forge->addKey('is_default', false, true, 'idx_is_default');
    }

    public function down(): void
    {
        $this->forge->dropKey('credentials', 'idx_workspace_type_default');
        $this->forge->dropKey('credentials', 'idx_workspace_type');
        $this->forge->dropKey('credentials', 'idx_is_default');
        $this->forge->dropColumn('credentials', 'is_default');
    }
}