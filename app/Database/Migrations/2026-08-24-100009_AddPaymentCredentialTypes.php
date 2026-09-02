<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Credential types payment gateway lokal: Midtrans & Tripay.
 */
class AddPaymentCredentialTypes extends Migration
{
    public function up(): void
    {
        $db   = \Config\Database::connect();
        $now  = date('Y-m-d H:i:s');

        $existing = [];
        foreach ($db->table('credential_types')->select('slug')->get()->getResultArray() as $row) {
            $existing[$row['slug']] = true;
        }

        $types = [
            [
                'name' => 'Midtrans',
                'slug' => 'midtrans',
                'schema_json' => json_encode([
                    ['key' => 'server_key', 'label' => 'Server Key', 'type' => 'password'],
                    ['key' => 'mode', 'label' => 'Mode', 'type' => 'select',
                     'options' => [['value' => 'sandbox'], ['value' => 'production']], 'default' => 'sandbox'],
                ], JSON_UNESCAPED_UNICODE),
            ],
            [
                'name' => 'Tripay',
                'slug' => 'tripay',
                'schema_json' => json_encode([
                    ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password'],
                    ['key' => 'private_key', 'label' => 'Private Key', 'type' => 'password'],
                    ['key' => 'merchant_code', 'label' => 'Kode Merchant', 'type' => 'text'],
                    ['key' => 'mode', 'label' => 'Mode', 'type' => 'select',
                     'options' => [['value' => 'sandbox'], ['value' => 'production']], 'default' => 'sandbox'],
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];

        foreach ($types as $t) {
            if (! isset($existing[$t['slug']])) {
                $db->table('credential_types')->insert([
                    'name'        => $t['name'],
                    'slug'        => $t['slug'],
                    'schema_json' => $t['schema_json'],
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        \Config\Database::connect()
            ->table('credential_types')
            ->whereIn('slug', ['midtrans', 'tripay'])
            ->delete();
    }
}
