<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\SystemSettingModel;
use App\Models\ActivityLogModel;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class SettingsController extends BaseController
{
    private function getAuthUserId()
    {
        $header = $this->request->getHeaderLine('Authorization');

        if (empty($header) || stripos($header, 'Bearer ') !== 0) {
            return null;
        }

        $token = trim(substr($header, 7));

        try {
            $decoded = JWT::decode($token, new Key(getenv('JWT_SECRET_KEY'), 'HS256'));
            return $decoded->sub ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Returns all system settings as a flat key => value map.
     */
    public function index()
    {
        $settingModel = new SystemSettingModel();
        return $this->response->setJSON(['data' => $settingModel->getAllAsMap()]);
    }

    /**
     * Updates one setting. Body: { "key": "facial_recognition_enabled", "value": "0" }
     */
    public function update()
    {
        $data = $this->request->getJSON(true);
        $key = $data['key'] ?? null;
        $value = $data['value'] ?? null;

        if (!$key || $value === null) {
            return $this->response->setStatusCode(422)->setJSON(['message' => 'key and value are required.']);
        }

        // Only these settings can be changed. The audit trail and the role
        // check are always on and cannot be switched off from here.
        $rules = [
            'facial_recognition_enabled' => ['0', '1'],
            'outbreak_threshold'         => 'number',
            'outbreak_window_days'       => 'number',
        ];

        if (!is_string($key) || !array_key_exists($key, $rules)) {
            return $this->response->setStatusCode(422)->setJSON(['message' => 'That setting cannot be changed.']);
        }

        $value = trim((string) $value);
        $rule  = $rules[$key];

        if (is_array($rule)) {
            if (!in_array($value, $rule, true)) {
                return $this->response->setStatusCode(422)->setJSON(['message' => 'Invalid value for this setting.']);
            }
        } elseif (!ctype_digit($value) || (int) $value < 1 || (int) $value > 365) {
            return $this->response->setStatusCode(422)->setJSON(['message' => 'Enter a whole number from 1 to 365.']);
        }
        
        $settingModel = new SystemSettingModel();
        $settingModel->setValue($key, (string) $value);

        $logModel = new ActivityLogModel();
        $logModel->log(
            $this->getAuthUserId(),
            'system_setting_updated',
            "Updated setting '{$key}' to '{$value}'"
        );

        return $this->response->setJSON(['message' => 'Setting updated successfully.']);
    }
}