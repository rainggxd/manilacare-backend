<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePatientsTable extends Migration
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
            'user_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
                'comment'    => 'Linked users.id once patient portal account is created',
            ],
            'health_center_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'family_number' => [
                'type'       => 'VARCHAR',
                'constraint' => '30',
                'null'       => true,
            ],
            // --- Basic Info (Registration screen) ---
            'full_name' => [
                'type'       => 'VARCHAR',
                'constraint' => '150',
            ],
            'contact_number' => [
                'type'       => 'VARCHAR',
                'constraint' => '20',
                'null'       => true,
            ],
            'date_of_birth' => [
                'type' => 'DATE',
            ],
            'email' => [
                'type'       => 'VARCHAR',
                'constraint' => '150',
                'null'       => true,
            ],
            'sex' => [
                'type'       => 'VARCHAR',
                'constraint' => '10',
            ],
            'place_of_birth' => [
                'type'       => 'VARCHAR',
                'constraint' => '150',
                'null'       => true,
            ],
            'password_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => '255',
                'null'       => true,
                'comment'    => 'For patient portal login, set when account is issued',
            ],
            'address' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'age' => [
                'type'       => 'INT',
                'constraint' => 3,
            ],
            'barangay_no' => [
                'type'       => 'VARCHAR',
                'constraint' => '20',
                'null'       => true,
            ],
            'civil_status' => [
                'type'       => 'VARCHAR',
                'constraint' => '20',
                'null'       => true,
            ],
            // --- Additional Info screen ---
            'fathers_name' => [
                'type'       => 'VARCHAR',
                'constraint' => '150',
                'null'       => true,
            ],
            'mothers_name' => [
                'type'       => 'VARCHAR',
                'constraint' => '150',
                'null'       => true,
            ],
            'height_cm' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'null'       => true,
            ],
            'weight_kg' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'null'       => true,
            ],
            'osca_pwd_no' => [
                'type'       => 'VARCHAR',
                'constraint' => '30',
                'null'       => true,
            ],
            'fourps_no' => [
                'type'       => 'VARCHAR',
                'constraint' => '30',
                'null'       => true,
            ],
            'religion' => [
                'type'       => 'VARCHAR',
                'constraint' => '50',
                'null'       => true,
            ],
            'educational_attainment' => [
                'type'       => 'VARCHAR',
                'constraint' => '50',
                'null'       => true,
            ],
            'philhealth_status' => [
                'type'       => 'VARCHAR',
                'constraint' => '10',
                'null'       => true,
                'comment'    => 'Member or Dependent',
            ],
            'philhealth_no' => [
                'type'       => 'VARCHAR',
                'constraint' => '30',
                'null'       => true,
            ],
            'philhealth_member_name' => [
                'type'       => 'VARCHAR',
                'constraint' => '150',
                'null'       => true,
            ],
            'philhealth_member_dob' => [
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
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('health_center_id', 'health_centers', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('patients');
    }

    public function down()
    {
        $this->forge->dropTable('patients');
    }
}