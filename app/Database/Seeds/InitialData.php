<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class InitialData extends Seeder
{
    public function run()
    {
        helper('text');

        $db = \Config\Database::connect();

        $userId = (int) $db->table('users')->insert([
            'name'       => 'Owner',
            'email'      => 'owner@local.dev',
            'password'   => password_hash('owner123', PASSWORD_DEFAULT),
            'role'       => 'owner',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], true);

        $workspaceId = (int) $db->table('workspaces')->insert([
            'name'        => 'Projek Utama',
            'description' => 'Projek default',
            'slug'        => 'projek-utama-' . random_string('alnum', 6),
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ], true);

        $db->table('workspace_users')->insert([
            'workspace_id' => $workspaceId,
            'user_id'      => $userId,
            'role'         => 'owner',
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        $db->table('credential_types')->insertBatch([
            [
                'name'        => '9Router',
                'slug'        => '9router',
                'schema_json' => json_encode([
                    ['key' => 'base_url', 'label' => 'Base URL', 'type' => 'text', 'default' => 'https://api.9router.com/v1'],
                    ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password'],
                ]),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name'        => 'Basic Auth',
                'slug'        => 'basic_auth',
                'schema_json' => json_encode([
                    ['key' => 'username', 'label' => 'Username', 'type' => 'text'],
                    ['key' => 'password', 'label' => 'Password', 'type' => 'password'],
                ]),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name'        => 'Bearer Token',
                'slug'        => 'bearer_token',
                'schema_json' => json_encode([
                    ['key' => 'token', 'label' => 'Token', 'type' => 'password'],
                ]),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        ]);

        $db->table('modules')->insertBatch([
            [
                'name' => 'Core', 'slug' => 'core', 'version' => '1.0.0', 'category' => 'Core', 'enabled' => 1,
                'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Trigger', 'slug' => 'trigger', 'version' => '1.0.0', 'category' => 'Trigger', 'enabled' => 1,
                'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Flow', 'slug' => 'flow', 'version' => '1.0.0', 'category' => 'Flow', 'enabled' => 1,
                'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Data', 'slug' => 'data', 'version' => '1.0.0', 'category' => 'Data', 'enabled' => 1,
                'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'HTTP', 'slug' => 'http', 'version' => '1.0.0', 'category' => 'HTTP', 'enabled' => 1,
                'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => '9Router AI', 'slug' => 'ai-9router', 'version' => '1.0.0', 'category' => 'AI', 'enabled' => 1,
                'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ],
        ]);

        echo "Seed selesai: user=$userId, workspace=$workspaceId\n";
    }
}
