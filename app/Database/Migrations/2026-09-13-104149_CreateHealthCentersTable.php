<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateHealthCentersTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'center_name' => [
                'type'       => 'VARCHAR',
                'constraint' => '150',
                'comment'    => 'e.g. Bo. Magsaysay Health Center, Foreshore Tondo Health Center',
            ],
            'address' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'contact_number' => [
                'type'       => 'VARCHAR',
                'constraint' => '20',
                'null'       => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('health_centers');
    }

    public function down()
    {
        $this->forge->dropTable('health_centers');
    }
}