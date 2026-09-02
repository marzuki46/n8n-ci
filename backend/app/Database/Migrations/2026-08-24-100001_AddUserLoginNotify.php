<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Preferensi notifikasi login per user.
 */
class AddUserLoginNotify extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('users', [
            'login_notify' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
                'null'       => false,
                'after'      => 'role',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('users', 'login_notify');
    }
}
