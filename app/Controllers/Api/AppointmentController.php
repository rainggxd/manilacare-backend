<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\AuthUser;
use App\Models\AppointmentModel;
use App\Models\PatientModel;
use App\Models\ActivityLogModel;
use CodeIgniter\HTTP\ResponseInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AppointmentController extends BaseController
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
     * List appointments, joined with the patient's name so the
     * frontend table doesn't need a second request per row.
     *
     * - Patient: only their own appointments (patient_id in the URL is ignored)
     * - Staff / Doctor: only appointments in their own health center
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

        $appointmentModel = new AppointmentModel();
        $patientId = $this->request->getGet('patient_id');
        $doctorId  = $this->request->getGet('doctor_user_id');
        $date      = $this->request->getGet('date');

        $query = $appointmentModel
            ->select('appointments.*, patients.full_name AS patient_name, doctors.full_name AS doctor_name')
            ->join('patients', 'patients.id = appointments.patient_id')
            ->join('users AS doctors', 'doctors.id = appointments.doctor_user_id', 'left')
            ->orderBy('appointments.appointment_date', 'ASC')
            ->orderBy('appointments.appointment_time', 'ASC');

        if ($auth->isRole('Patient')) {
            $query->where('appointments.patient_id', $auth->patientId() ?? 0);
        } else {
            if (!$auth->isRole('Master Admin')) {
                $query->where('appointments.health_center_id', $auth->healthCenterId);
            }
            if (!empty($patientId)) {
                $query->where('appointments.patient_id', $patientId);
            }
        }

        if (!empty($doctorId)) {
            $query->where('appointments.doctor_user_id', $doctorId);
        }

        if (!empty($date)) {
            $query->where('appointments.appointment_date', $date);
        }

        return $this->response->setJSON(['data' => $query->findAll()]);
    }

    /**
     * Create a new appointment request. Status always starts at
     * "Pending" — a Doctor/Admin approves or rejects it afterwards.
     * A Patient can only book for themselves; Staff/Doctor only for
     * patients in their own health center.
     */
    public function create()
    {
        $data = $this->request->getJSON(true);

        $patientModel = new PatientModel();
        $patient = $patientModel->find($data['patient_id'] ?? null);

        if (!$patient) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)
                ->setJSON(['message' => 'Selected patient was not found.']);
        }

        $auth = AuthUser::fromRequest($this->request);
        if (!$auth) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON(['message' => 'Authentication required.']);
        }

        $allowed = $auth->isRole('Patient')
            ? ((int) $auth->patientId() === (int) $patient['id'])
            : $auth->canAccessCenter($patient['health_center_id']);

        if (!$allowed) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
                ->setJSON(['message' => 'You cannot create an appointment for this patient.']);
        }

        $appointmentModel = new AppointmentModel();

        $appointmentData = [
            'patient_id'        => $data['patient_id'],
            'health_center_id'  => $patient['health_center_id'],
            'doctor_user_id'    => $data['doctor_user_id'] ?? null,
            'appointment_date'  => $data['appointment_date'] ?? null,
            'appointment_time'  => $data['appointment_time'] ?? null,
            'reason'            => $data['reason'] ?? null,
            'status'            => 'Pending',
        ];

        if (!$appointmentModel->validate($appointmentData)) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)
                ->setJSON(['message' => 'Validation failed.', 'errors' => $appointmentModel->errors()]);
        }

        $appointmentId = $appointmentModel->insert($appointmentData, true);

        $logModel = new ActivityLogModel();
        $logModel->log(
            $this->getAuthUserId(),
            'appointment_created',
            'Created appointment for: ' . ($patient['full_name'] ?? 'Unknown')
        );

        return $this->response
            ->setStatusCode(ResponseInterface::HTTP_CREATED)
            ->setJSON(['message' => 'Appointment created.', 'appointment_id' => $appointmentId]);
    }

    /**
     * Approve, reject, or mark an appointment as completed.
     * Expects JSON body: { "status": "Approved" }
     */
    public function updateStatus($id = null)
    {
        $data = $this->request->getJSON(true);
        $status = $data['status'] ?? null;

        if (!in_array($status, ['Pending', 'Approved', 'Rejected', 'Completed'], true)) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)
                ->setJSON(['message' => 'Invalid status value.']);
        }

        $appointmentModel = new AppointmentModel();
        $appointment = $appointmentModel
            ->select('appointments.*, patients.full_name AS patient_name')
            ->join('patients', 'patients.id = appointments.patient_id')
            ->find($id);

        if (!$appointment) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_NOT_FOUND)
                ->setJSON(['message' => 'Appointment not found.']);
        }

        $auth = AuthUser::fromRequest($this->request);
        if (!$auth || !$auth->canAccessCenter($appointment['health_center_id'])) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
                ->setJSON(['message' => 'You are not allowed to modify this appointment.']);
        }

        $updateData = ['status' => $status];

        // If a doctor acts on an unassigned appointment, assign it to them
        if ($auth->isRole('Doctor') && empty($appointment['doctor_user_id']) && $status !== 'Pending') {
            $updateData['doctor_user_id'] = $auth->id;
        }

        $appointmentModel->update($id, $updateData);

        $logModel = new ActivityLogModel();
        $logModel->log(
            $this->getAuthUserId(),
            'appointment_status_updated',
            'Marked appointment for ' . ($appointment['patient_name'] ?? 'Unknown') . ' as ' . $status
        );

        return $this->response->setJSON(['message' => 'Appointment status updated.']);
    }

    /**
     * Permanently delete an appointment.
     */
    public function delete($id = null)
    {
        $appointmentModel = new AppointmentModel();
        $appointment = $appointmentModel
            ->select('appointments.*, patients.full_name AS patient_name')
            ->join('patients', 'patients.id = appointments.patient_id')
            ->find($id);

        if (!$appointment) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_NOT_FOUND)
                ->setJSON(['message' => 'Appointment not found.']);
        }

        $auth = AuthUser::fromRequest($this->request);
        if (!$auth || !$auth->canAccessCenter($appointment['health_center_id'])) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
                ->setJSON(['message' => 'You are not allowed to modify this appointment.']);
        }

        $appointmentModel->delete($id);

        $logModel = new ActivityLogModel();
        $logModel->log(
            $this->getAuthUserId(),
            'appointment_deleted',
            'Deleted appointment for: ' . ($appointment['patient_name'] ?? 'Unknown')
        );

        return $this->response->setJSON(['message' => 'Appointment deleted.']);
    }
}