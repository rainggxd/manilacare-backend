<?php

namespace App\Models;

use CodeIgniter\Model;

class VisitModel extends Model
{
    protected $table         = 'visits';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'patient_id',
        'health_center_id',
        'appointment_id',
        'attended_by',
        'visit_date',
        'visit_time',
        'purpose',
        'status',
        'queue_number',
    ];

    protected $validationRules = [
        'patient_id'       => 'required|is_natural_no_zero',
        'health_center_id' => 'required|is_natural_no_zero',
        'visit_date'       => 'required|valid_date',
        'status'           => 'permit_empty|in_list[Checked-in,In Progress,Completed,Cancelled]',
    ];
}