<?php

namespace App\Models;

use CodeIgniter\Model;

class MedicalRecordModel extends Model
{
    protected $table         = 'medical_records';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'visit_id',
        'patient_id',
        'doctor_user_id',
        'chief_complaint',
        'diagnosis',
        'treatment_plan',
        'notes',
        'record_date',
    ];

    protected $validationRules = [
        'visit_id'       => 'required|is_natural_no_zero',
        'patient_id'     => 'required|is_natural_no_zero',
        'doctor_user_id' => 'required|is_natural_no_zero',
        'record_date'    => 'required|valid_date',
    ];
}