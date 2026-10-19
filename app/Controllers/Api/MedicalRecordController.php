<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\AuthUser;
use App\Models\MedicalRecordModel;
use App\Models\VisitModel;
use App\Models\ActivityLogModel;
use CodeIgniter\HTTP\ResponseInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class MedicalRecordController extends BaseController
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
     * List medical records, joined with the patient's name.
     * Optional ?patient_id= filter for one patient's full history.
     *
     * - Patient: only their own records (patient_id in the URL is ignored)
     * - Staff / Doctor: only patients in their own health center
     * - Master Admin: all centers
     */
    public function index()
    {
        $auth = AuthUser::fromRequest($this->request);
        if (!$auth) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON(['message' => 'Authentication required.']);
        }

        $medicalRecordModel = new MedicalRecordModel();
        $patientId = $this->request->getGet('patient_id');

        $query = $medicalRecordModel
            ->select('medical_records.*, patients.full_name AS patient_name')
            ->join('patients', 'patients.id = medical_records.patient_id')
            ->orderBy('medical_records.record_date', 'DESC');

        if ($auth->isRole('Patient')) {
            $query->where('medical_records.patient_id', $auth->patientId() ?? 0);
        } else {
            if (!$auth->isRole('Master Admin')) {
                $query->where('patients.health_center_id', $auth->healthCenterId);
            }
            if (!empty($patientId)) {
                $query->where('medical_records.patient_id', $patientId);
            }
        }

        return $this->response->setJSON(['data' => $query->findAll()]);
    }

    /**
     * Doctor writes a medical record for a visit (diagnosis,
     * treatment plan, notes). One visit should only have one
     * record — if it already has one, this updates it instead.
     * Doctors only, and only for visits in their own health center.
     */
    public function create()
    {
        $auth = AuthUser::fromRequest($this->request);
        if (!$auth || !$auth->isRole('Doctor')) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
                ->setJSON(['message' => 'Only doctors can write medical records.']);
        }

        $data = $this->request->getJSON(true);

        $visitModel = new VisitModel();
        $visit = $visitModel->find($data['visit_id'] ?? null);

        if (!$visit) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)
                ->setJSON(['message' => 'Selected visit was not found.']);
        }

        if (!$auth->canAccessCenter($visit['health_center_id'])) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
                ->setJSON(['message' => 'This visit belongs to another health center.']);
        }

        $medicalRecordModel = new MedicalRecordModel();

        $recordData = [
            'visit_id'        => $visit['id'],
            'patient_id'      => $visit['patient_id'],
            'doctor_user_id'  => $auth->id,
            'chief_complaint' => $data['chief_complaint'] ?? null,
            'diagnosis'       => $data['diagnosis'] ?? null,
            'treatment_plan'  => $data['treatment_plan'] ?? null,
            'notes'           => $data['notes'] ?? null,
            'record_date'     => $data['record_date'] ?? date('Y-m-d'),
        ];

        if (!$medicalRecordModel->validate($recordData)) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)
                ->setJSON(['message' => 'Validation failed.', 'errors' => $medicalRecordModel->errors()]);
        }

        $existing = $medicalRecordModel->where('visit_id', $visit['id'])->first();

        if ($existing) {
            $medicalRecordModel->update($existing['id'], $recordData);
            $recordId = $existing['id'];
        } else {
            $recordId = $medicalRecordModel->insert($recordData, true);
        }

        // Writing the record marks the visit as done
        $visitModel->update($visit['id'], ['status' => 'Completed']);

        $logModel = new ActivityLogModel();
        $logModel->log(
            $this->getAuthUserId(),
            'medical_record_saved',
            'Saved medical record for visit #' . $visit['id']
        );

        return $this->response
            ->setStatusCode(ResponseInterface::HTTP_CREATED)
            ->setJSON(['message' => 'Medical record saved.', 'record_id' => $recordId]);
    }

    /**
     * Show a single medical record. Same visibility rules as index().
     */
    public function show($id = null)
    {
        $medicalRecordModel = new MedicalRecordModel();
        $record = $medicalRecordModel
            ->select('medical_records.*, patients.full_name AS patient_name, patients.health_center_id AS patient_health_center_id')
            ->join('patients', 'patients.id = medical_records.patient_id')
            ->find($id);

        if (!$record) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_NOT_FOUND)
                ->setJSON(['message' => 'Medical record not found.']);
        }

        $auth = AuthUser::fromRequest($this->request);
        if (!$auth) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON(['message' => 'Authentication required.']);
        }

        $allowed = $auth->isRole('Patient')
            ? ((int) $auth->patientId() === (int) $record['patient_id'])
            : $auth->canAccessCenter($record['patient_health_center_id']);

        if (!$allowed) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
                ->setJSON(['message' => 'You are not allowed to view this record.']);
        }

        unset($record['patient_health_center_id']);

        return $this->response->setJSON(['data' => $record]);
    }
}