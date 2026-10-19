<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMedicalRecordsTable extends Migration
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
            'visit_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'patient_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'comment'    => 'Denormalized from visits.patient_id for simpler queries',
            ],
            'doctor_user_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'comment'    => 'users.id of the Doctor who wrote this record',
            ],
            'chief_complaint' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'diagnosis' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'treatment_plan' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'record_date' => [
                'type' => 'DATE',
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
        $this->forge->addForeignKey('visit_id', 'visits', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('patient_id', 'patients', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('doctor_user_id', 'users', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('medical_records');
    }

    public function down()
    {
        $this->forge->dropTable('medical_records');
    }
}