<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateImmunizationRecordsTable extends Migration
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
            'vaccine_name' => [
                'type'       => 'VARCHAR',
                'constraint' => '20',
                'comment'    => 'e.g. BCG, HEPA B, PENTA 1, OPV 1, PCV 1, IPV 1, IPV 2, PENTA 2, OPV 2, PCV 2, PENTA 3, OPV 3, PCV 3, MR 1',
            ],
            'date_given' => [
                'type' => 'DATE',
                'null' => true,
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
        $this->forge->addForeignKey('patient_id', 'patients', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('immunization_records');
    }

    public function down()
    {
        $this->forge->dropTable('immunization_records');
    }
}