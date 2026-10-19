<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateObHistoryTable extends Migration
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
                'comment'    => 'Only populated for patients within reproductive age (<=49 y/o per DOH)',
            ],
            // Newborn Screening / Hearing Test
            'newborn_screening_date' => ['type' => 'DATE', 'null' => true],
            'newborn_screening_result' => ['type' => 'VARCHAR', 'constraint' => '50', 'null' => true],
            'hearing_test_date' => ['type' => 'DATE', 'null' => true],
            'hearing_test_result' => ['type' => 'VARCHAR', 'constraint' => '50', 'null' => true],
            // Birth details
            'birth_order' => ['type' => 'VARCHAR', 'constraint' => '20', 'null' => true],
            'birth_length_cm' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'birth_weight_kg' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'birth_attendant' => ['type' => 'VARCHAR', 'constraint' => '10', 'null' => true, 'comment' => 'SBA or NON-SBA'],
            'place_of_delivery' => ['type' => 'VARCHAR', 'constraint' => '10', 'null' => true, 'comment' => 'FB or NFB'],
            'type_of_delivery' => ['type' => 'VARCHAR', 'constraint' => '50', 'null' => true],
            'type_of_feeding' => ['type' => 'VARCHAR', 'constraint' => '50', 'null' => true],
            // Vitamin A
            'vitamin_a_dose' => ['type' => 'VARCHAR', 'constraint' => '10', 'null' => true, 'comment' => '100K IU or 200K IU'],
            'vitamin_a_date_given' => ['type' => 'DATE', 'null' => true],
            // Deworming
            'deworming_1st_dose_date' => ['type' => 'DATE', 'null' => true],
            'deworming_2nd_dose_date' => ['type' => 'DATE', 'null' => true],
            // OB History proper
            'gravida' => ['type' => 'VARCHAR', 'constraint' => '10', 'null' => true, 'comment' => 'G'],
            'para' => ['type' => 'VARCHAR', 'constraint' => '10', 'null' => true, 'comment' => 'P'],
            'menarche' => ['type' => 'VARCHAR', 'constraint' => '20', 'null' => true],
            'pmp' => ['type' => 'DATE', 'null' => true, 'comment' => 'Previous Menstrual Period'],
            'lmp' => ['type' => 'DATE', 'null' => true, 'comment' => 'Last Menstrual Period'],
            'edc' => ['type' => 'DATE', 'null' => true, 'comment' => 'Expected Date of Confinement'],
            'tt_status' => ['type' => 'VARCHAR', 'constraint' => '20', 'null' => true],
            'td_1' => ['type' => 'DATE', 'null' => true],
            'td_2' => ['type' => 'DATE', 'null' => true],
            'td_3' => ['type' => 'DATE', 'null' => true],
            'td_4' => ['type' => 'DATE', 'null' => true],
            'td_5' => ['type' => 'DATE', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('patient_id', 'patients', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('ob_history');
    }

    public function down()
    {
        $this->forge->dropTable('ob_history');
    }
}