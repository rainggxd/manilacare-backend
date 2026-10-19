<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\AuthUser;
use App\Models\ActivityLogModel;
use CodeIgniter\HTTP\ResponseInterface;

class ActivityLogController extends BaseController
{
    /**
     * List activity logs, newest first, joined with the acting user's
     * name (falls back to "System" when user_id is null or the user
     * was later deleted).
     *
     * - Master Admin: logs of every health center
     * - Admin: only logs made by users of their own health center
     */
    public function index()
    {
        $auth = AuthUser::fromRequest($this->request);
        if (!$auth) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON(['message' => 'Authentication required.']);
        }

        $logModel = new ActivityLogModel();

        $query = $logModel
            ->select('activity_logs.*, users.full_name AS actor_name, users.role_id')
            ->join('users', 'users.id = activity_logs.user_id', 'left')
            ->orderBy('activity_logs.created_at', 'DESC')
            ->limit(200);

        if (!$auth->isRole('Master Admin')) {
            if ($auth->healthCenterId <= 0) {
                return $this->response->setJSON(['data' => []]);
            }
            $query->where('users.health_center_id', $auth->healthCenterId);
        }

        return $this->response->setJSON(['data' => $query->findAll()]);
    }
}