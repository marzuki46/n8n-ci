<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Credential type untuk gateway WhatsApp lokal (Fonnte).
 */
class AddWhatsappCredentialType extends Migration
{
    public function up(): void
    {
        $db = \Config\Database::connect();
        $existing = [];
        foreach ($db->table('credential_types')->select('slug')->get()->getResultArray() as $row) {
            $existing[$row['slug']] = true;
        }

        if (! isset($existing['fonnte'])) {
            $now = date('Y-m-d H:i:s');
            $db->table('credential_types')->insert([
                'name'        => 'WhatsApp (Fonnte)',
                'slug'        => 'fonnte',
                'schema_json' => json_encode([
                    ['key' => 'token', 'label' => 'Device Token', 'type' => 'password'],
                ], JSON_UNESCAPED_UNICODE),
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    public function down(): void
    {
        \Config\Database::connect()
            ->table('credential_types')->where('slug', 'fonnte')->delete();
    }
}
