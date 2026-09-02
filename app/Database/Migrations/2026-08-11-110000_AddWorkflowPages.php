<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddWorkflowPages extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('workflow_pages')) {
            $this->forge->addField([
                'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'workflow_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => false],
                'node_id'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'slug'         => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
                'title'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'html'         => ['type' => 'LONGTEXT', 'null' => true],
                'created_at'   => ['type' => 'DATETIME', 'null' => true],
                'updated_at'   => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('slug');
            $this->forge->addKey('workflow_id');
            $this->forge->createTable('workflow_pages');
        }
    }

    public function down()
    {
        if ($this->db->tableExists('workflow_pages')) {
            $this->forge->dropTable('workflow_pages');
        }
    }
}
