<?php

namespace App\Models;

use CodeIgniter\Model;

class AppointmentModel extends Model
{
    protected $table            = 'appointments';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';

    protected $allowedFields = [
        'patient_id',
        'health_center_id',
        'doctor_user_id',
        'appointment_date',
        'appointment_time',
        'reason',
        'status',
    ];

    protected $validationRules = [
        'patient_id'        => 'required|is_natural_no_zero',
        'health_center_id'  =>       'required|is_natural_no_zero',
        'doctor_user_id'    => 'permit_empty|is_natural_no_zero',
        'appointment_date'  => 'required|valid_date',
        'appointment_time'  => 'required',
        'status'            => 'permit_empty|in_list[Pending,Approved,Rejected,Completed]',
    ];
}