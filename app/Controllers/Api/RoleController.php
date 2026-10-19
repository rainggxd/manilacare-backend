<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\AuthUser;
use App\Models\UserModel;

class RoleController extends BaseController
{
    /**
     * List roles for the role dropdown in User Management.
     * Master Admin sees all roles; an Admin only sees Staff and Doctor,
     * the two roles they are allowed to create.
     */
    public function index()
    {
        $auth = AuthUser::fromRequest($this->request);

        $userModel = new UserModel();
        $builder = $userModel->db->table('roles')->orderBy('id', 'ASC');

        if ($auth && !$auth->isRole('Master Admin')) {
            $builder->whereIn('role_name', ['Staff', 'Doctor']);
        }

        return $this->response->setJSON(['data' => $builder->get()->getResultArray()]);
    }
}