<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Hardening & reliability:
 * - webhook_requests.workspace_id (scoping antar proyek)
 * - users.pending_email / token verifikasi ganti email
 * - payment_events (dedup callback gateway, unik ref+status)
 */
class AddHardeningAndPaymentsDedup extends Migration
{
    public function up(): void
    {
        // 1) Inspector scoping
        $this->forge->addColumn('webhook_requests', [
            'workspace_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'workflow_id',
            ],
        ]);
        $this->forge->addKey(['workspace_id']);
        $this->forge->processIndexes('webhook_requests');

        // 2) Ganti email dengan verifikasi
        $this->forge->addColumn('users', [
            'pending_email' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
                'null'       => true,
                'after'      => 'email',
            ],
            'pending_email_token' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'after'      => 'pending_email',
            ],
            'pending_email_expires' => [
                'type'  => 'DATETIME',
                'null'  => true,
                'after' => 'pending_email_token',
            ],
        ]);

        // 3) Dedup callback payment — unik per provider+reference+status.
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'provider' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'reference' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'execution_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['provider', 'reference', 'status']);
        $this->forge->createTable('payment_events', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('payment_events', true);
        $this->forge->dropColumn('users', ['pending_email', 'pending_email_token', 'pending_email_expires']);
        $this->forge->dropColumn('webhook_requests', 'workspace_id');
    }
}
