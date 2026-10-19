<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddLoginAttemptsAndOtpAttempts extends Migration
{
    public function up()
    {
        // Failed login log (used for the 5-attempts / 15-minute lockout)
        $this->forge->addField([
            'id'           => ['type' => 'SERIAL'],
            'email'        => ['type' => 'VARCHAR', 'constraint' => 150],
            'attempted_at' => ['type' => 'TIMESTAMP', 'null' => false],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('login_attempts', true);
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_login_attempts_email ON login_attempts (email, attempted_at)');

        // Wrong-code counter for the password-reset OTP
        $this->forge->addColumn('password_resets', [
            'attempts' => ['type' => 'INT', 'default' => 0],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('password_resets', 'attempts');
        $this->forge->dropTable('login_attempts', true);
    }
}