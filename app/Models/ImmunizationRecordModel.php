<?php

namespace App\Models;

use CodeIgniter\Model;

class ImmunizationRecordModel extends Model
{
    protected $table         = 'immunization_records';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'patient_id',
        'vaccine_name',
        'date_given',
    ];
}