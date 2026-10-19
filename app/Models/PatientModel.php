<?php

namespace App\Models;

use CodeIgniter\Model;

class PatientModel extends Model
{
    protected $table         = 'patients';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'user_id',
        'health_center_id',
        'family_number',
        'full_name',
        'contact_number',
        'date_of_birth',
        'email',
        'sex',
        'place_of_birth',
        'password_hash',
        'address',
        'age',
        'barangay_no',
        'civil_status',
        'fathers_name',
        'mothers_name',
        'height_cm',
        'weight_kg',
        'osca_pwd_no',
        'fourps_no',
        'religion',
        'educational_attainment',
        'philhealth_status',
        'philhealth_no',
        'philhealth_member_name',
        'philhealth_member_dob',
        'disease_type',
        'disease_updated_at',
    ];

    protected $validationRules = [
        'full_name'        => 'required|min_length[2]|max_length[150]',
        'date_of_birth'    => 'required|valid_date',
        'sex'              => 'required|in_list[Male,Female]',
        'health_center_id' => 'required|integer',
        'age'              => 'required|integer|greater_than_equal_to[0]',
    ];

    public static function shouldHaveObHistory(int $age): bool
    {
        return $age <= 49;
    }
}