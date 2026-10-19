<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDoctorToAppointmentsTable extends Migration
{
    public function up()
    {
        $this->forge->addColumn('appointments', [
            'doctor_user_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
                'comment'    => 'users.id of the Doctor assigned to this appointment',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('appointments', 'doctor_user_id');
    }
}