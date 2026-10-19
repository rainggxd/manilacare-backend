<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\AuthUser;
use App\Models\VisitModel;
use App\Models\PatientModel;
use App\Models\AppointmentModel;
use App\Models\ActivityLogModel;
use CodeIgniter\HTTP\ResponseInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class VisitController extends BaseController
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
     * List visits, joined with the patient's name so the frontend
     * table doesn't need a second request per row.
     *
     * - Patient: only their own visits (patient_id in the URL is ignored)
     * - Staff / Doctor: only visits in their own health center
     * - Master Admin: all centers
     * Optional ?patient_id= filter for a single patient's visit history.
     */
    public function index()
    {
        $auth = AuthUser::fromRequest($this->request);
        if (!$auth) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON(['message' => 'Authentication required.']);
        }

        $visitModel = new VisitModel();
        $patientId = $this->request->getGet('patient_id');

        $query = $visitModel
            ->select('visits.*, patients.full_name AS patient_name')
            ->join('patients', 'patients.id = visits.patient_id')
            ->orderBy('visits.visit_date', 'DESC')
            ->orderBy('visits.visit_time', 'DESC');

        if ($auth->isRole('Patient')) {
            $query->where('visits.patient_id', $auth->patientId() ?? 0);
        } else {
            if (!$auth->isRole('Master Admin')) {
                $query->where('visits.health_center_id', $auth->healthCenterId);
            }
            if (!empty($patientId)) {
                $query->where('visits.patient_id', $patientId);
            }
        }

        return $this->response->setJSON(['data' => $query->findAll()]);
    }

    /**
     * Show a single visit, including its medical record if the
     * doctor has already written one for it.
     */
    public function show($id = null)
    {
        $visitModel = new VisitModel();
        $visit = $visitModel
            ->select('visits.*, patients.full_name AS patient_name')
            ->join('patients', 'patients.id = visits.patient_id')
            ->find($id);

        if (!$visit) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_NOT_FOUND)
                ->setJSON(['message' => 'Visit not found.']);
        }

        $auth = AuthUser::fromRequest($this->request);
        if (!$auth) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON(['message' => 'Authentication required.']);
        }

        $allowed = $auth->isRole('Patient')
            ? ((int) $auth->patientId() === (int) $visit['patient_id'])
            : $auth->canAccessCenter($visit['health_center_id']);

        if (!$allowed) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
                ->setJSON(['message' => 'You are not allowed to view this visit.']);
        }

        $medicalRecordModel = new \App\Models\MedicalRecordModel();
        $visit['medical_record'] = $medicalRecordModel->where('visit_id', $id)->first();

        return $this->response->setJSON(['data' => $visit]);
    }

    /**
     * Check a patient in for a visit. Used by Staff at the front
     * desk — either against an existing appointment (pass
     * appointment_id) or as a walk-in (leave it out). Assigns a
     * daily queue number (resets every day, separate for each health
     * center) used by the "Call Next Patient" queue on the Staff Dashboard.
     *
     * Staff can only check in patients of their own health center.
     */
    public function checkIn()
    {
        $auth = AuthUser::fromRequest($this->request);
        if (!$auth) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON(['message' => 'Authentication required.']);
        }

        $data = $this->request->getJSON(true);

        $patientModel = new PatientModel();
        $patient = $patientModel->find($data['patient_id'] ?? null);

        if (!$patient) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)
                ->setJSON(['message' => 'Selected patient was not found.']);
        }

        if ($auth->isRole('Patient') || !$auth->canAccessCenter($patient['health_center_id'])) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
                ->setJSON(['message' => 'This patient belongs to another health center.']);
        }

        $appointmentId = $data['appointment_id'] ?? null;

        // If this check-in is against a booked appointment, make sure it exists
        // and belongs to this patient.
        $appointmentModel = new AppointmentModel();
        if (!empty($appointmentId)) {
            $appointment = $appointmentModel->find($appointmentId);

            if (!$appointment) {
                return $this->response
                    ->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)
                    ->setJSON(['message' => 'That appointment was not found.']);
            }

            if ((int) $appointment['patient_id'] !== (int) $patient['id']) {
                return $this->response
                    ->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)
                    ->setJSON(['message' => 'That appointment does not belong to this patient.']);
            }
        }

        $visitModel = new VisitModel();

        // The visit is always for today, using the server's date and time.
        // (A client cannot back-date or future-date a check-in.)
        $visitDate = date('Y-m-d');

        $visitData = [
            'patient_id'       => $patient['id'],
            'health_center_id' => $patient['health_center_id'],
            'appointment_id'   => !empty($appointmentId) ? $appointmentId : null,
            'attended_by'      => $this->getAuthUserId(),
            'visit_date'       => $visitDate,
            'visit_time'       => date('H:i:s'),
            'purpose'          => $data['purpose'] ?? (!empty($appointmentId) ? 'Follow-up' : 'Walk-in'),
            'status'           => 'Checked-in',
        ];

        if (!$visitModel->validate($visitData)) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)
                ->setJSON(['message' => 'Validation failed.', 'errors' => $visitModel->errors()]);
        }

        $db = \Config\Database::connect();
        $db->transBegin();

        try {
            // One check-in at a time per health center. Without this, two staff members
            // checking in at the same moment could get the same queue number.
            $db->query('SELECT pg_advisory_xact_lock(?, ?)', [1001, (int) $patient['health_center_id']]);

            // A patient who is still waiting or being served cannot be checked in again today.
            $alreadyIn = (new VisitModel())
                ->where('patient_id', $patient['id'])
                ->where('visit_date', $visitDate)
                ->whereIn('status', ['Checked-in', 'In Progress'])
                ->first();

            if ($alreadyIn) {
                $db->transRollback();

                return $this->response
                    ->setStatusCode(ResponseInterface::HTTP_CONFLICT)
                    ->setJSON([
                        'message' => 'This patient is already checked in today (queue #' . ($alreadyIn['queue_number'] ?? '?') . ').',
                    ]);
            }

            // Next queue number for this day AND this health center
            $lastToday = (new VisitModel())
                ->where('visit_date', $visitDate)
                ->where('health_center_id', $patient['health_center_id'])
                ->orderBy('queue_number', 'DESC')
                ->first();
            $nextQueueNumber = $lastToday && $lastToday['queue_number'] ? ((int) $lastToday['queue_number'] + 1) : 1;

            $visitData['queue_number'] = $nextQueueNumber;

            $visitId = $visitModel->insert($visitData, true);

            if (!$visitId) {
                throw new \RuntimeException('Unable to save the visit.');
            }

            // Mark the booked appointment as Completed only after the visit was saved.
            if (!empty($appointmentId)) {
                $appointmentModel->update($appointmentId, ['status' => 'Completed']);
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Check-in failed: ' . $e->getMessage());

            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_INTERNAL_SERVER_ERROR)
                ->setJSON(['message' => 'Unable to check in the patient. Please try again.']);
        }

        $logModel = new ActivityLogModel();
        $logModel->log(
            $this->getAuthUserId(),
            'patient_checked_in',
            'Checked in patient: ' . ($patient['full_name'] ?? 'Unknown') . " (queue #{$nextQueueNumber})"
        );

        return $this->response
            ->setStatusCode(ResponseInterface::HTTP_CREATED)
            ->setJSON(['message' => 'Patient checked in.', 'visit_id' => $visitId, 'queue_number' => $nextQueueNumber]);
    }

    /**
     * Update a visit's status (e.g. "In Progress" once the doctor
     * calls the patient in, "Completed" once the record is written).
     * Not allowed for Patients; Staff/Doctor only within their own center.
     */
    public function updateStatus($id = null)
    {
        $data = $this->request->getJSON(true);
        $status = $data['status'] ?? null;

        if (!in_array($status, ['Checked-in', 'In Progress', 'Completed', 'Cancelled'], true)) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)
                ->setJSON(['message' => 'Invalid status value.']);
        }

        $visitModel = new VisitModel();
        $visit = $visitModel->find($id);

        if (!$visit) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_NOT_FOUND)
                ->setJSON(['message' => 'Visit not found.']);
        }

        $auth = AuthUser::fromRequest($this->request);
        if (!$auth || $auth->isRole('Patient') || !$auth->canAccessCenter($visit['health_center_id'])) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
                ->setJSON(['message' => 'You are not allowed to update this visit.']);
        }

        $visitModel->update($id, ['status' => $status]);

        return $this->response->setJSON(['message' => 'Visit status updated.']);
    }

    /**
     * Marks the currently-serving visit (status "In Progress") as
     * Completed, then promotes the next "Checked-in" visit — the
     * one with the lowest queue number today — to "In Progress".
     * Used by the "Call Next Patient" button on the Staff Dashboard.
     *
     * The queue is per health center: Staff only call patients in
     * their own center. A Master Admin must pass health_center_id
     * in the JSON body to say which queue to call.
     */
    public function callNext()
    {
        $auth = AuthUser::fromRequest($this->request);
        if (!$auth || $auth->isRole('Patient')) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
                ->setJSON(['message' => 'You are not allowed to call patients.']);
        }

        if ($auth->isRole('Master Admin')) {
            $body = $this->request->getJSON(true);
            $centerId = $body['health_center_id'] ?? null;
            if (empty($centerId)) {
                return $this->response
                    ->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)
                    ->setJSON(['message' => 'Please specify which health center queue to call (health_center_id).']);
            }
        } else {
            $centerId = $auth->healthCenterId;
        }

        $visitModel = new VisitModel();
        $today = date('Y-m-d');

        // Complete whichever visit is currently being served in this center
        $current = $visitModel
            ->where('visit_date', $today)
            ->where('health_center_id', $centerId)
            ->where('status', 'In Progress')
            ->first();

        if ($current) {
            $visitModel->update($current['id'], ['status' => 'Completed']);
        }

        // Promote the next one in line (same center)
        $next = $visitModel
            ->where('visit_date', $today)
            ->where('health_center_id', $centerId)
            ->where('status', 'Checked-in')
            ->orderBy('queue_number', 'ASC')
            ->first();

        if (!$next) {
            return $this->response->setJSON(['message' => 'Queue is empty.', 'data' => null]);
        }

        $visitModel->update($next['id'], ['status' => 'In Progress']);

        $patientModel = new PatientModel();
        $patient = $patientModel->find($next['patient_id']);

        $logModel = new ActivityLogModel();
        $logModel->log(
            $this->getAuthUserId(),
            'queue_called_next',
            "Called queue #{$next['queue_number']} (" . ($patient['full_name'] ?? 'Unknown') . ')'
        );

        $next['patient_name'] = $patient['full_name'] ?? null;

        return $this->response->setJSON(['data' => $next, 'message' => 'Next patient called.']);
    }
}