<?php

namespace App\Models;

use CodeIgniter\Model;

class SystemSettingModel extends Model
{
    protected $table         = 'system_settings';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'setting_key',
        'setting_value',
        'updated_at',
    ];

    /**
     * Returns all settings as a flat [key => value] map.
     */
    public function getAllAsMap(): array
    {
        $rows = $this->findAll();
        $map = [];
        foreach ($rows as $row) {
            $map[$row['setting_key']] = $row['setting_value'];
        }
        return $map;
    }

    /**
     * Updates (or creates) a single setting by key.
     */
    public function setValue(string $key, string $value): void
    {
        $existing = $this->where('setting_key', $key)->first();

        if ($existing) {
            $this->update($existing['id'], [
                'setting_value' => $value,
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
        } else {
            $this->insert([
                'setting_key'   => $key,
                'setting_value' => $value,
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
        }
    }
}