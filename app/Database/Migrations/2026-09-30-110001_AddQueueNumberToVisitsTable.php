<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddQueueNumberToVisitsTable extends Migration
{
    public function up()
    {
        $this->forge->addColumn('visits', [
            'queue_number' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
                'comment'    => 'Daily queue number, resets each day, assigned on check-in',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('visits', 'queue_number');
    }
}