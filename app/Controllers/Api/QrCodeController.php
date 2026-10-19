<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\AuthUser;
use App\Models\QrCodeModel;
use App\Models\PatientModel;
use App\Models\ActivityLogModel;
use CodeIgniter\HTTP\ResponseInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class QrCodeController extends BaseController
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
     * Lists all QR codes on file, joined with the patient's name,
     * for the QR Management view. Master Admin sees every center;
     * an Admin only sees QR codes of patients in their own center.
     */
    public function index()
    {
        $auth = AuthUser::fromRequest($this->request);
        if (!$auth || !$auth->isRole('Master Admin', 'Admin')) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
                ->setJSON(['message' => 'Only admins can manage QR codes.']);
        }

        $qrModel = new QrCodeModel();
        $query = $qrModel
            ->select('qr_codes.*, patients.full_name AS patient_name, patients.family_number')
            ->join('patients', 'patients.id = qr_codes.patient_id')
            ->orderBy('qr_codes.created_at', 'DESC');

        if (!$auth->isRole('Master Admin')) {
            $query->where('patients.health_center_id', $auth->healthCenterId);
        }

        $rows = $query->findAll();

        return $this->response->setJSON(['data' => $rows]);
    }

    /**
     * Generates (or regenerates) a QR code for a given patient.
     * Body: { "patient_id": 5 }. Master Admin, or an Admin for patients
     * of their own health center.
     */
    public function generate()
    {
        $auth = AuthUser::fromRequest($this->request);
        if (!$auth || !$auth->isRole('Master Admin', 'Admin')) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
                ->setJSON(['message' => 'Only admins can generate QR codes.']);
        }

        $data = $this->request->getJSON(true);
        $patientId = $data['patient_id'] ?? null;

        $patientModel = new PatientModel();
        $patient = $patientModel->find($patientId);

        if (!$patient) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)
                ->setJSON(['message' => 'Patient not found.']);
        }

        if (!$auth->canAccessCenter($patient['health_center_id'])) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
                ->setJSON(['message' => 'This patient belongs to another health center.']);
        }

        $qrModel = new QrCodeModel();
        $existing = $qrModel->where('patient_id', $patientId)->first();
        $token = $qrModel->generateToken();

        if ($existing) {
            $qrModel->update($existing['id'], ['qr_token' => $token, 'is_active' => 1]);
            $qrId = $existing['id'];
        } else {
            $qrId = $qrModel->insert([
                'patient_id' => $patientId,
                'qr_token'   => $token,
                'is_active'  => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ], true);
        }

        $logModel = new ActivityLogModel();
        $logModel->log(
            $this->getAuthUserId(),
            'qr_code_generated',
            'Generated QR code for patient: ' . ($patient['full_name'] ?? 'Unknown')
        );

        return $this->response->setJSON([
            'message'    => 'QR code generated.',
            'qr_code_id' => $qrId,
            'qr_token'   => $token,
        ]);
    }

    /**
     * Looks up a patient by scanned QR token. Used by Staff's
     * "Scan QR" tool to pull up the patient's record after a scan.
     *
     * Patients are never allowed to scan. Staff can only scan patients
     * of their own health center; Master Admin can scan any.
     */
    public function lookup($token = null)
    {
        $auth = AuthUser::fromRequest($this->request);
        if (!$auth) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON(['message' => 'Authentication required.']);
        }

        if ($auth->isRole('Patient')) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
                ->setJSON(['message' => 'Patients cannot scan QR codes.']);
        }

        $qrModel = new QrCodeModel();
        $qr = $qrModel
            ->select('qr_codes.*, patients.*')
            ->join('patients', 'patients.id = qr_codes.patient_id')
            ->where('qr_codes.qr_token', $token)
            ->where('qr_codes.is_active', 1)
            ->first();

        if (!$qr) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_NOT_FOUND)
                ->setJSON(['message' => 'QR code not recognized or inactive.']);
        }

        // health_center_id here comes from the patients table (patients.* is selected last)
        if (!$auth->canAccessCenter($qr['health_center_id'])) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
                ->setJSON(['message' => 'This patient belongs to another health center.']);
        }

        unset($qr['password_hash']);

        // Log the scan so the dashboard can later count real QR scans.
        $logModel = new ActivityLogModel();
        $logModel->log(
            $this->getAuthUserId(),
            'qr_code_scanned',
            'Scanned QR code of patient: ' . ($qr['full_name'] ?? 'Unknown')
        );

        return $this->response->setJSON(['data' => $qr]);
    }
}