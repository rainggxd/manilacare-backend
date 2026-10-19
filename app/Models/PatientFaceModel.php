<?php

namespace App\Models;

use CodeIgniter\Model;

class PatientFaceModel extends Model
{
    protected $table         = 'patient_faces';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'patient_id',
        'descriptor',
        'consent_at',
        'enrolled_by',
        'created_at',
        'updated_at',
    ];
}