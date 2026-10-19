<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\AuthUser;
use App\Models\PatientModel;
use App\Models\PrescriptionModel;
use App\Models\ActivityLogModel;
use CodeIgniter\HTTP\ResponseInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class PrescriptionController extends BaseController
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
     * List all prescriptions for a given patient, newest first,
     * joined with the prescribing doctor's name.
     *
     * - Patient: only their own prescriptions
     * - Staff / Doctor: only patients in their own health center
     * - Master Admin: any patient
     */
    public function index()
    {
        $auth = AuthUser::fromRequest($this->request);
        if (!$auth) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON(['message' => 'Authentication required.']);
        }

        $patientId = $this->request->getGet('patient_id');

        if (empty($patientId)) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_BAD_REQUEST)
                ->setJSON(['message' => 'patient_id is required.']);
        }

        $patientModel = new PatientModel();
        $patient = $patientModel->find($patientId);

        if (!$patient) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_NOT_FOUND)
                ->setJSON(['message' => 'Patient not found.']);
        }

        $allowed = $auth->isRole('Patient')
            ? ((int) $auth->patientId() === (int) $patient['id'])
            : $auth->canAccessCenter($patient['health_center_id']);

        if (!$allowed) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
                ->setJSON(['message' => 'You are not allowed to view these prescriptions.']);
        }

        $prescriptionModel = new PrescriptionModel();
        $prescriptions = $prescriptionModel
            ->select('prescriptions.*, users.full_name AS doctor_name')
            ->join('users', 'users.id = prescriptions.doctor_user_id', 'left')
            ->where('prescriptions.patient_id', $patientId)
            ->orderBy('date_prescribed', 'DESC')
            ->orderBy('prescriptions.created_at', 'DESC')
            ->findAll();

        return $this->response->setJSON(['data' => $prescriptions]);
    }

    /**
     * Create a new prescription for a patient. Automatically attributes
     * it to the logged-in doctor via their JWT. Doctors only, and only
     * for patients in their own health center.
     */
    public function create()
    {
        $auth = AuthUser::fromRequest($this->request);
        if (!$auth || !$auth->isRole('Doctor')) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
                ->setJSON(['message' => 'Only doctors can add prescriptions.']);
        }

        $data = $this->request->getJSON(true);
        $doctorId = $auth->id;

        if (empty($data['patient_id']) || empty($data['medication']) || empty($data['date_prescribed'])) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)
                ->setJSON(['message' => 'Patient, medication, and date are required.']);
        }

        $patientModel = new PatientModel();
        $patient = $patientModel->find($data['patient_id']);

        if (!$patient) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)
                ->setJSON(['message' => 'Selected patient was not found.']);
        }

        if (!$auth->canAccessCenter($patient['health_center_id'])) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
                ->setJSON(['message' => 'This patient belongs to another health center.']);
        }

        $prescriptionModel = new PrescriptionModel();

        $prescriptionId = $prescriptionModel->insert([
            'patient_id'       => $data['patient_id'],
            'doctor_user_id'   => $doctorId,
            'medication'       => $data['medication'],
            'dosage'           => $data['dosage'] ?? null,
            'frequency'        => $data['frequency'] ?? null,
            'duration'         => $data['duration'] ?? null,
            'instructions'     => $data['instructions'] ?? null,
            'date_prescribed'  => $data['date_prescribed'],
        ], true);

        $logModel = new ActivityLogModel();
        $logModel->log(
            $doctorId,
            'prescription_created',
            'Prescribed ' . $data['medication'] . ' for patient #' . $data['patient_id']
        );

        return $this->response
            ->setStatusCode(ResponseInterface::HTTP_CREATED)
            ->setJSON(['message' => 'Prescription added.', 'prescription_id' => $prescriptionId]);
    }
}