<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePrescriptionsTable extends Migration
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
            'doctor_user_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'medication' => [
                'type'       => 'VARCHAR',
                'constraint' => '150',
            ],
            'dosage' => [
                'type'       => 'VARCHAR',
                'constraint' => '50',
                'null'       => true,
            ],
            'frequency' => [
                'type'       => 'VARCHAR',
                'constraint' => '50',
                'null'       => true,
            ],
            'duration' => [
                'type'       => 'VARCHAR',
                'constraint' => '50',
                'null'       => true,
            ],
            'instructions' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'date_prescribed' => [
                'type' => 'DATE',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('patient_id', 'patients', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('doctor_user_id', 'users', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('prescriptions', true); // IF NOT EXISTS — table already exists in most local DBs
    }

    public function down()
    {
        $this->forge->dropTable('prescriptions');
    }
}