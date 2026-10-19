<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table            = 'users';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useSoftDeletes   = true;
    protected $allowedFields    = [
        'role_id',
        'health_center_id',
        'full_name',
        'email',
        'password_hash',
        'is_active',
        'last_login',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    /**
     * Find an active user by email, joined with role name.
     * Used by AuthController for login lookups.
     */
    public function findActiveByEmail(string $email): ?array
    {
        return $this->select('users.*, roles.role_name')
            ->join('roles', 'roles.id = users.role_id')
            ->where('users.email', $email)
            ->where('users.is_active', 1)
            ->first();
    }
}