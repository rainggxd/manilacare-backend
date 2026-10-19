<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds the "Admin" role: a health-center administrator who can only see
 * and manage the data of their own health center. The Master Admin
 * stays the only role that sees both centers.
 */
class AddAdminRole extends Migration
{
    public function up()
    {
        $this->db->query(
            "INSERT INTO roles (role_name, created_at, updated_at)
             SELECT 'Admin', NOW(), NOW()
             WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_name = 'Admin')"
        );
    }

    public function down()
    {
        $this->db->query("DELETE FROM roles WHERE role_name = 'Admin'");
    }
}