<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Paket 1 — Default credential per proyek.
 * Tambah kolom is_default ke tabel credentials + indeks pendukung.
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
                'null'       => false,
                'after'      => 'status',
            ],
        ]);

        // Indeks untuk pencarian default per (workspace, type).
        // Pada tabel existing, addKey butuh processIndexes() agar benar-benar dibuat.
        $this->forge->addKey(['workspace_id', 'credential_type_id', 'is_default']);
        $this->forge->processIndexes('credentials');
    }

    public function down(): void
    {
        // Kolom dihapus dulu; indeks yang memuat kolom ikut hilang di MySQL.
        $this->forge->dropColumn('credentials', 'is_default');
    }
}
