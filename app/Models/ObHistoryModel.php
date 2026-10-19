<?php

namespace App\Models;

use CodeIgniter\Model;

class ObHistoryModel extends Model
{
    protected $table         = 'ob_history';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'patient_id',
        'newborn_screening_date',
        'newborn_screening_result',
        'hearing_test_date',
        'hearing_test_result',
        'birth_order',
        'birth_length_cm',
        'birth_weight_kg',
        'birth_attendant',
        'place_of_delivery',
        'type_of_delivery',
        'type_of_feeding',
        'vitamin_a_dose',
        'vitamin_a_date_given',
        'deworming_1st_dose_date',
        'deworming_2nd_dose_date',
        'gravida',
        'para',
        'menarche',
        'pmp',
        'lmp',
        'edc',
        'tt_status',
        'td_1',
        'td_2',
        'td_3',
        'td_4',
        'td_5',
    ];
}