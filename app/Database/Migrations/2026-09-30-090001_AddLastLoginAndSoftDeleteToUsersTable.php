<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddLastLoginAndSoftDeleteToUsersTable extends Migration
{
    public function up()
    {
        $this->forge->addColumn('users', [
            'last_login' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'comment' => 'Set on every successful login',
            ],
            'deleted_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'comment' => 'Soft-delete timestamp for account recovery',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('users', 'last_login');
        $this->forge->dropColumn('users', 'deleted_at');
    }
}