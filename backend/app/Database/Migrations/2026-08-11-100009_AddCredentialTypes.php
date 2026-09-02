<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCredentialTypes extends Migration
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
                'name'        => 'OpenAI',
                'slug'        => 'openai',
                'schema_json' => [
                    ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password'],
                    ['key' => 'base_url', 'label' => 'Base URL', 'type' => 'text', 'default' => 'https://api.openai.com/v1'],
                ],
            ],
            [
                'name'        => 'GitHub',
                'slug'        => 'github',
                'schema_json' => [
                    ['key' => 'token', 'label' => 'Personal Access Token', 'type' => 'password'],
                    ['key' => 'username', 'label' => 'Username', 'type' => 'text'],
                ],
            ],
            [
                'name'        => 'Notion',
                'slug'        => 'notion',
                'schema_json' => [
                    ['key' => 'token', 'label' => 'Integration Token', 'type' => 'password'],
                ],
            ],
            [
                'name'        => 'MySQL',
                'slug'        => 'mysql',
                'schema_json' => [
                    ['key' => 'host', 'label' => 'Host', 'type' => 'text', 'default' => '127.0.0.1'],
                    ['key' => 'port', 'label' => 'Port', 'type' => 'text', 'default' => '3306'],
                    ['key' => 'user', 'label' => 'User', 'type' => 'text'],
                    ['key' => 'password', 'label' => 'Password', 'type' => 'password'],
                    ['key' => 'database', 'label' => 'Database', 'type' => 'text'],
                ],
            ],
            [
                'name'        => 'PostgreSQL',
                'slug'        => 'postgres',
                'schema_json' => [
                    ['key' => 'host', 'label' => 'Host', 'type' => 'text', 'default' => '127.0.0.1'],
                    ['key' => 'port', 'label' => 'Port', 'type' => 'text', 'default' => '5432'],
                    ['key' => 'user', 'label' => 'User', 'type' => 'text'],
                    ['key' => 'password', 'label' => 'Password', 'type' => 'password'],
                    ['key' => 'database', 'label' => 'Database', 'type' => 'text'],
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
        foreach (['openai', 'github', 'notion', 'mysql', 'postgres'] as $slug) {
            $db->table('credential_types')->where('slug', $slug)->delete();
        }
    }
}
