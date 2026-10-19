<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\HealthCenterModel;
use App\Models\ActivityLogModel;
use CodeIgniter\HTTP\ResponseInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class HealthCenterController extends BaseController
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
     * List all health centers covered by the system.
     */
    public function index()
    {
        $healthCenterModel = new HealthCenterModel();
        $centers = $healthCenterModel->orderBy('id', 'ASC')->findAll();

        return $this->response->setJSON(['data' => $centers]);
    }

    /**
     * Add a new health center to the system.
     */
    public function create()
    {
        $data = $this->request->getJSON(true);

        $healthCenterModel = new HealthCenterModel();

        $centerData = [
            'center_name'    => $data['center_name'] ?? null,
            'address'        => $data['address'] ?? null,
            'contact_number' => $data['contact_number'] ?? null,
        ];

        if (!$healthCenterModel->validate($centerData)) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)
                ->setJSON(['message' => 'Validation failed.', 'errors' => $healthCenterModel->errors()]);
        }

        $centerId = $healthCenterModel->insert($centerData, true);

        $logModel = new ActivityLogModel();
        $logModel->log(
            $this->getAuthUserId(),
            'health_center_added',
            'Added health center: ' . $centerData['center_name']
        );

        return $this->response
            ->setStatusCode(ResponseInterface::HTTP_CREATED)
            ->setJSON(['message' => 'Health center added successfully.', 'health_center_id' => $centerId]);
    }
}