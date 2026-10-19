<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Stores one face template per patient for check-in verification.
 *
 * The template is only a list of 128 numbers produced by face-api.js.
 * No photo is saved. If the patient is deleted, the template is deleted too.
 */
class CreatePatientFacesTable extends Migration
{
    public function up()
    {
        $this->db->query(
            'CREATE TABLE IF NOT EXISTS patient_faces (
                id          SERIAL PRIMARY KEY,
                patient_id  INT NOT NULL UNIQUE REFERENCES patients(id) ON DELETE CASCADE,
                descriptor  TEXT NOT NULL,
                consent_at  TIMESTAMP NOT NULL,
                enrolled_by INT NULL,
                created_at  TIMESTAMP NULL,
                updated_at  TIMESTAMP NULL
            )'
        );
    }

    public function down()
    {
        $this->forge->dropTable('patient_faces', true);
    }
}