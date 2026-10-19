<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateQrCodesTable extends Migration
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
            'patient_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'qr_token' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'is_active' => [
                'type'       => 'SMALLINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('patient_id');
        $this->forge->addUniqueKey('qr_token');
        $this->forge->addForeignKey('patient_id', 'patients', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('qr_codes', true);
    }

    public function down()
    {
        $this->forge->dropTable('qr_codes', true);
    }
}