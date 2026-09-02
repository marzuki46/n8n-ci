<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAppCredentialTypes extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();
        $existing = [];
        foreach ($db->table('credential_types')->select('slug')->get()->getResultArray() as $row) {
            $existing[$row['slug']] = true;
        }

        $types = [
            [
                'name'        => 'SMTP (Email)',
                'slug'        => 'smtp',
                'schema_json' => [
                    ['key' => 'host', 'label' => 'SMTP Host', 'type' => 'text', 'placeholder' => 'smtp.gmail.com'],
                    ['key' => 'port', 'label' => 'Port', 'type' => 'text', 'default' => '587'],
                    ['key' => 'secure', 'label' => 'Enkripsi', 'type' => 'select', 'default' => 'tls', 'options' => [
                        ['value' => 'tls', 'label' => 'STARTTLS (587)'],
                        ['value' => 'ssl', 'label' => 'SSL (465)'],
                        ['value' => 'none', 'label' => 'Tanpa enkripsi'],
                    ]],
                    ['key' => 'user', 'label' => 'Username', 'type' => 'text'],
                    ['key' => 'password', 'label' => 'Password', 'type' => 'password'],
                ],
            ],
            [
                'name'        => 'Telegram Bot',
                'slug'        => 'telegram',
                'schema_json' => [
                    ['key' => 'bot_token', 'label' => 'Bot Token', 'type' => 'password', 'placeholder' => '123456:ABC-DEF...'],
                ],
            ],
            [
                'name'        => 'Discord Webhook',
                'slug'        => 'discord',
                'schema_json' => [
                    ['key' => 'webhook_url', 'label' => 'Webhook URL', 'type' => 'text', 'placeholder' => 'https://discord.com/api/webhooks/...'],
                ],
            ],
            [
                'name'        => 'Slack Webhook',
                'slug'        => 'slack',
                'schema_json' => [
                    ['key' => 'webhook_url', 'label' => 'Webhook URL', 'type' => 'text', 'placeholder' => 'https://hooks.slack.com/services/...'],
                ],
            ],
        ];

        $now = date('Y-m-d H:i:s');
        foreach ($types as $type) {
            if (isset($existing[$type['slug']])) {
                continue;
            }
            $db->table('credential_types')->insert([
                'name'        => $type['name'],
                'slug'        => $type['slug'],
                'schema_json' => json_encode($type['schema_json'], JSON_UNESCAPED_UNICODE),
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    public function down()
    {
        $db = \Config\Database::connect();
        foreach (['smtp', 'telegram', 'discord', 'slack'] as $slug) {
            $db->table('credential_types')->where('slug', $slug)->delete();
        }
    }
}
