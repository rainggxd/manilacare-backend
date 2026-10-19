<?php

namespace App\Libraries;

use App\Models\PatientModel;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthUser
{
    public $id;
    public $role;
    public $healthCenterId;

    /** Returns an AuthUser, or null if there is no valid token. */
    public static function fromRequest($request)
    {
        $header = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return null;
        }

        try {
            $payload = JWT::decode($matches[1], new Key(getenv('JWT_SECRET_KEY'), 'HS256'));
        } catch (\Throwable $e) {
            return null;
        }

        $user = new self();
        $user->id   = $payload->sub ?? null;
        $user->role = $payload->role ?? null;
        // 0 = no health center assigned. No real center has id 0, so such an
        // account matches nothing (safer than NULL, which could match rows
        // that have no center).
        $user->healthCenterId = isset($payload->health_center_id) ? (int) $payload->health_center_id : 0;

        return $user;
    }

    public function isRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /** Master Admin can access any center; Staff/Doctor only their own. */
    public function canAccessCenter($centerId): bool
    {
        if ($this->role === 'Master Admin') {
            return true;
        }

        return $this->healthCenterId > 0
            && $centerId !== null
            && (int) $this->healthCenterId === (int) $centerId;
    }

    /** For a Patient login: the id of their own row in `patients`. */
    public function patientId()
    {
        if ($this->role !== 'Patient') {
            return null;
        }

        $row = (new PatientModel())->select('id')->where('user_id', $this->id)->first();
        return $row['id'] ?? null;
    }
}