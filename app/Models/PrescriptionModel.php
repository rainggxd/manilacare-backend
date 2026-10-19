<?php

namespace App\Models;

use CodeIgniter\Model;

class PrescriptionModel extends Model
{
    protected $table         = 'prescriptions';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'patient_id',
        'doctor_user_id',
        'medication',
        'dosage',
        'frequency',
        'duration',
        'instructions',
        'date_prescribed',
    ];
}