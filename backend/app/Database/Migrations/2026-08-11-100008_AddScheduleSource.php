<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddScheduleSource extends Migration
{
    public function up()
    {
        $fields = [
            'source' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'manual',
                'null'       => false,
                'after'      => 'timezone',
            ],
        ];

        $this->forge->addColumn('schedules', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('schedules', 'source');
    }
}
