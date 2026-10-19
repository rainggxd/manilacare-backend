<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSystemSettingsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'setting_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'setting_value' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('setting_key');
        $this->forge->createTable('system_settings', true);

        // Seed the three toggles used by the Admin Settings page.
        $this->db->table('system_settings')->insertBatch([
            ['setting_key' => 'facial_recognition_enabled', 'setting_value' => '1', 'updated_at' => date('Y-m-d H:i:s')],
            ['setting_key' => 'rbac_enabled',                'setting_value' => '1', 'updated_at' => date('Y-m-d H:i:s')],
            ['setting_key' => 'audit_trail_enabled',         'setting_value' => '1', 'updated_at' => date('Y-m-d H:i:s')],
        ]);
    }

    public function down()
    {
        $this->forge->dropTable('system_settings', true);
    }
}