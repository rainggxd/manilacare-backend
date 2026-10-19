<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDiseaseUpdatedAtToPatients extends Migration
{
    public function up()
    {
        $this->forge->addColumn('patients', [
            'disease_updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        // Patients who already have a diagnosis: use their last update
        // time so they still count toward the recent-cases total.
        $this->db->query(
            "UPDATE patients
             SET disease_updated_at = COALESCE(updated_at, created_at)
             WHERE disease_type IS NOT NULL AND TRIM(disease_type) <> ''"
        );
    }

    public function down()
    {
        $this->forge->dropColumn('patients', 'disease_updated_at');
    }
}