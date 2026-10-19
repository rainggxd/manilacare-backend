<?php

namespace App\Models;

use CodeIgniter\Model;

class HealthCenterModel extends Model
{
    protected $table         = 'health_centers';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'center_name',
        'address',
        'contact_number',
    ];

    protected $validationRules = [
        'center_name' => 'required|max_length[150]',
        'address'     => 'permit_empty',
        'contact_number' => 'permit_empty|max_length[20]',
    ];
}