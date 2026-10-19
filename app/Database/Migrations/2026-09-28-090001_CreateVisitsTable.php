<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateVisitsTable extends Migration
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
            'health_center_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'appointment_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
                'comment'    => 'Set when this visit came from a booked appointment; null for a walk-in',
            ],
            'attended_by' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
                'comment'    => 'users.id of the Staff who checked the patient in',
            ],
            'visit_date' => [
                'type' => 'DATE',
            ],
            'visit_time' => [
                'type' => 'TIME',
                'null' => true,
            ],
            'purpose' => [
                'type'       => 'VARCHAR',
                'constraint' => '100',
                'null'       => true,
                'comment'    => 'e.g. Consultation, Follow-up, Walk-in, Immunization',
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => '20',
                'default'    => 'Checked-in',
                'comment'    => 'Checked-in, In Progress, Completed, Cancelled',
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
        $this->forge->addForeignKey('health_center_id', 'health_centers', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('appointment_id', 'appointments', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('attended_by', 'users', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('visits');
    }

    public function down()
    {
        $this->forge->dropTable('visits');
    }
}