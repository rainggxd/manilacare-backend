<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAppointmentsTable extends Migration
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
            'appointment_date' => [
                'type' => 'DATE',
            ],
            'appointment_time' => [
                'type' => 'TIME',
            ],
            'reason' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => '20',
                'default'    => 'Pending',
                'comment'    => 'Pending, Approved, Rejected, Completed',
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
        $this->forge->createTable('appointments', true); // IF NOT EXISTS — table already exists in most local DBs
    }

    public function down()
    {
        $this->forge->dropTable('appointments');
    }
}