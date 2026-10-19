<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\AuthUser;
use App\Models\PatientModel;
use App\Models\ImmunizationRecordModel;
use App\Models\ObHistoryModel;
use App\Models\UserModel;
use App\Models\ActivityLogModel;
use CodeIgniter\HTTP\ResponseInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Libraries\PasswordPolicy;
use App\Libraries\FaceTemplate;
use App\Models\PatientFaceModel;

class PatientController extends BaseController
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
     * Check that the given email has a verified OTP row from
     * EmailVerificationController, created within the last 30 minutes
     * (so a stale verification from a long-abandoned form can't be
     * reused indefinitely).
     */
    private function isEmailVerified(string $email): bool
    {
        $db = \Config\Database::connect();
        $row = $db->table('email_verifications')
            ->where('email', $email)
            ->where('verified', true)
            ->where('created_at >=', date('Y-m-d H:i:s', strtotime('-30 minutes')))
            ->orderBy('created_at', 'DESC')
            ->get()
            ->getRowArray();

        return (bool) $row;
    }

    /**
     * Register a new patient.
     * Patient accounts are created by Master Admin / Staff only (the
     * route is guarded by the "role" filter). Staff can only register
     * patients into their own health center.
     */
    public function register()
    {
        $auth = AuthUser::fromRequest($this->request);
        if (!$auth) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON(['message' => 'Authentication required.']);
        }

        $data = $this->request->getJSON(true);

        // Staff and Admin can only register into their own health center.
        if (!$auth->isRole('Master Admin')) {
            $data['health_center_id'] = $auth->healthCenterId;
        }

        $email = $data['email'] ?? null;

        if (empty($email) || !$this->isEmailVerified($email)) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)
                ->setJSON(['message' => 'This email address has not been verified. Please verify it before registering.']);
        }

        // A face scan (with consent) is required, unless facial recognition is off in Settings.
        $faceDescriptor = null;
        if (FaceTemplate::enabled()) {
            $faceDescriptor = FaceTemplate::clean($data['face_descriptor'] ?? null);
            if ($faceDescriptor === null || ($data['face_consent'] ?? false) !== true) {
                return $this->response
                    ->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)
                    ->setJSON(['message' => 'A face scan with the patient\'s consent is required to register a patient.']);
            }
        }

        // The email must not already belong to an account or a patient.
        $emailTaken = (new UserModel())->withDeleted()->where('email', $email)->first()
            || (new PatientModel())->where('email', $email)->first();
        if ($emailTaken) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)
                ->setJSON(['message' => 'This email is already registered. Please use a different email.']);
        }

        $patientModel = new PatientModel();

        // Basic + Additional Info fields go straight into patients table
        $patientData = [
            'health_center_id'       => $data['health_center_id'] ?? null,
            'family_number'          => $data['family_number'] ?? null,
            'full_name'              => $data['full_name'] ?? null,
            'contact_number'         => $data['contact_number'] ?? null,
            'date_of_birth'          => $data['date_of_birth'] ?? null,
            'email'                  => $data['email'] ?? null,
            'sex'                    => $data['sex'] ?? null,
            'place_of_birth'         => $data['place_of_birth'] ?? null,
            'address'                => $data['address'] ?? null,
            'age'                    => $data['age'] ?? null,
            'barangay_no'            => $data['barangay_no'] ?? null,
            'civil_status'           => $data['civil_status'] ?? null,
            'fathers_name'           => $data['fathers_name'] ?? null,
            'mothers_name'           => $data['mothers_name'] ?? null,
            'height_cm'              => $data['height_cm'] ?? null,
            'weight_kg'              => $data['weight_kg'] ?? null,
            'osca_pwd_no'            => $data['osca_pwd_no'] ?? null,
            'fourps_no'              => $data['fourps_no'] ?? null,
            'religion'               => $data['religion'] ?? null,
            'educational_attainment' => $data['educational_attainment'] ?? null,
            'philhealth_status'      => $data['philhealth_status'] ?? null,
            'philhealth_no'          => $data['philhealth_no'] ?? null,
            'philhealth_member_name' => $data['philhealth_member_name'] ?? null,
            'philhealth_member_dob'  => $data['philhealth_member_dob'] ?? null,
            'disease_type'           => $data['disease_type'] ?? null,
        ];

        // Remember when the diagnosis was set (used by the outbreak warning)
        if (!empty(trim((string) ($patientData['disease_type'] ?? '')))) {
            $patientData['disease_updated_at'] = date('Y-m-d H:i:s');
        }

        // Optional patient-portal password (Admin can issue credentials later instead)
        if (!empty($data['password'])) {
            $policyError = PasswordPolicy::check((string) $data['password']);
            if ($policyError) {
                return $this->response
                    ->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)
                    ->setJSON(['message' => $policyError]);
            }
            $patientData['password_hash'] = password_hash($data['password'], PASSWORD_BCRYPT);
        }

        if (!$patientModel->validate($patientData)) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)
                ->setJSON(['message' => 'Validation failed.', 'errors' => $patientModel->errors()]);
        }

        // Everything below is saved together. If any step fails, nothing is kept,
        // so there are no half-registered patients (for example a patient with no
        // login account or no face).
        $db = \Config\Database::connect();
        $db->transBegin();

        try {
            $patientId = $patientModel->insert($patientData, true);

            if (!$patientId) {
                throw new \RuntimeException('Unable to save the patient row.');
            }

            // Save the face right away, together with the patient.
            if ($faceDescriptor !== null) {
                $now = date('Y-m-d H:i:s');
                $faceSaved = (new PatientFaceModel())->insert([
                    'patient_id'  => $patientId,
                    'descriptor'  => json_encode($faceDescriptor),
                    'consent_at'  => $now,
                    'enrolled_by' => (int) $auth->id,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);

                if (!$faceSaved) {
                    throw new \RuntimeException('Unable to save the face template.');
                }
            }

            // Also create a linked login account in `users` so the patient can log
            // in via the same AuthController/login flow used by Admin/Staff/Doctor.
            if (!empty($patientData['password_hash']) && !empty($patientData['email'])) {
                $userModel = new UserModel();

                $patientRole = $userModel->db->table('roles')
                    ->where('role_name', 'Patient')
                    ->get()
                    ->getRow();

                $userId = $userModel->insert([
                    'role_id'          => $patientRole->id ?? 4,
                    'health_center_id' => $patientData['health_center_id'],
                    'full_name'        => $patientData['full_name'],
                    'email'            => $patientData['email'],
                    'password_hash'    => $patientData['password_hash'],
                    'is_active'        => 1,
                ], true);

                if (!$userId) {
                    throw new \RuntimeException('Unable to create the patient login account.');
                }

                $patientModel->update($patientId, ['user_id' => $userId]);
            }

            // Optional: immunization records (array of { vaccine_name, date_given })
            if (!empty($data['immunization_records']) && is_array($data['immunization_records'])) {
                $immunizationModel = new ImmunizationRecordModel();
                foreach ($data['immunization_records'] as $record) {
                    if (empty($record['vaccine_name'])) {
                        continue;
                    }
                    $immunizationModel->insert([
                        'patient_id'   => $patientId,
                        'vaccine_name' => $record['vaccine_name'],
                        'date_given'   => $record['date_given'] ?? null,
                    ]);
                }
            }

            // Optional: OB history, only meaningful if the patient is within reproductive age
            $age = (int) ($data['age'] ?? 0);
            if (PatientModel::shouldHaveObHistory($age) && !empty($data['ob_history']) && is_array($data['ob_history'])) {
                $obHistoryModel = new ObHistoryModel();
                $obData = $data['ob_history'];
                $obData['patient_id'] = $patientId;
                $obHistoryModel->insert($obData);
            }

            // The email verification can only be used once.
            $db->table('email_verifications')->where('email', $email)->delete();

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Patient registration failed: ' . $e->getMessage());

            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_INTERNAL_SERVER_ERROR)
                ->setJSON(['message' => 'Unable to save the patient record. Nothing was saved. Please try again.']);
        }

        // Logs are written after the save is complete.
        $logModel = new ActivityLogModel();
        if ($faceDescriptor !== null) {
            $logModel->log(
                (int) $auth->id,
                'face_enrolled',
                'Enrolled the face of patient: ' . ($patientData['full_name'] ?? 'Unknown')
            );
        }
        $logModel->log(
            (int) $auth->id,
            'patient_registered',
            'Registered new patient: ' . ($patientData['full_name'] ?? 'Unknown')
        );

        return $this->response
            ->setStatusCode(ResponseInterface::HTTP_CREATED)
            ->setJSON([
                'message'    => 'Patient registered successfully.',
                'patient_id' => $patientId,
            ]);
    }

    /**
     * List patients (basic, for Patient Registration table view).
     * Master Admin sees every center; Staff/Doctor only their own.
     */
    public function index()
    {
        $auth = AuthUser::fromRequest($this->request);
        if (!$auth) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON(['message' => 'Authentication required.']);
        }

        $patientModel = new PatientModel();
        $patientModel
            ->select('id, family_number, full_name, health_center_id, age, sex, contact_number, disease_type, created_at')
            ->orderBy('created_at', 'DESC');

        if (!$auth->isRole('Master Admin')) {
            $patientModel->where('health_center_id', $auth->healthCenterId);
        }

        return $this->response->setJSON(['data' => $patientModel->findAll()]);
    }

    /**
     * Update a patient's disease type / diagnosis. Used by Doctor
     * accounts after a consultation — Master Admin does not set this
     * during registration.
     */
    public function updateDisease($id = null)
    {
        $data = $this->request->getJSON(true);
        $diseaseType = $data['disease_type'] ?? null;

        $patientModel = new PatientModel();
        $patient = $patientModel->find($id);

        if (!$patient) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_NOT_FOUND)
                ->setJSON(['message' => 'Patient not found.']);
        }

        $auth = AuthUser::fromRequest($this->request);
        if (!$auth || !$auth->canAccessCenter($patient['health_center_id'])) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
                ->setJSON(['message' => 'You are not allowed to update this patient.']);
        }

        $diseaseType = trim((string) $diseaseType);
        $diseaseType = $diseaseType === '' ? null : $diseaseType;

        // disease_updated_at feeds the outbreak warning (cases per week)
        $patientModel->update($id, [
            'disease_type'       => $diseaseType,
            'disease_updated_at' => $diseaseType === null ? null : date('Y-m-d H:i:s'),
        ]);

        $logModel = new ActivityLogModel();
        $logModel->log(
            $this->getAuthUserId(),
            'patient_disease_updated',
            'Updated diagnosis for patient: ' . ($patient['full_name'] ?? 'Unknown')
        );

        return $this->response->setJSON(['message' => 'Diagnosis updated.']);
    }

    /**
     * Return the currently logged-in patient's own record, resolved
     * from their linked user account (patients.user_id = JWT sub).
     * Used by the Patient portal to know "who am I" without exposing
     * other patients' data.
     */
    public function me()
    {
        $userId = $this->getAuthUserId();

        if (!$userId) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON(['message' => 'Not authenticated.']);
        }

        $patientModel = new PatientModel();
        $patient = $patientModel->where('user_id', $userId)->first();

        if (!$patient) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_NOT_FOUND)
                ->setJSON(['message' => 'No patient record linked to this account.']);
        }

        unset($patient['password_hash']);

        $immunizationModel = new ImmunizationRecordModel();
        $patient['immunization_records'] = $immunizationModel
            ->where('patient_id', $patient['id'])
            ->findAll();

        $obHistoryModel = new ObHistoryModel();
        $patient['ob_history'] = $obHistoryModel
            ->where('patient_id', $patient['id'])
            ->first();

        return $this->response->setJSON(['data' => $patient]);
    }

    /**
     * Show a single patient's full record, including immunization
     * records and OB history (if any). Master Admin can open any
     * patient; Staff/Doctor only patients in their own health center;
     * a Patient only their own record.
     */
    public function show($id = null)
    {
        $patientModel = new PatientModel();
        $patient = $patientModel->find($id);

        if (!$patient) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_NOT_FOUND)
                ->setJSON(['message' => 'Patient not found.']);
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
                ->setJSON(['message' => 'You are not allowed to view this patient.']);
        }

        // Never expose the password hash to the frontend
        unset($patient['password_hash']);

        $immunizationModel = new ImmunizationRecordModel();
        $patient['immunization_records'] = $immunizationModel
            ->where('patient_id', $id)
            ->findAll();

        $obHistoryModel = new ObHistoryModel();
        $patient['ob_history'] = $obHistoryModel
            ->where('patient_id', $id)
            ->first();

        return $this->response->setJSON(['data' => $patient]);
    }

    /**
     * Permanently delete a patient record, plus anything linked to it
     * (immunization records, OB history, and the linked patient-portal
     * user account, if one exists) so no orphan rows are left behind.
     */
    public function delete($id = null)
    {
        $patientModel = new PatientModel();
        $patient = $patientModel->find($id);

        if (!$patient) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_NOT_FOUND)
                ->setJSON(['message' => 'Patient not found.']);
        }

        // Master Admin: any patient. Admin: only patients of their own center.
        $auth = AuthUser::fromRequest($this->request);
        if (!$auth || !$auth->canAccessCenter($patient['health_center_id'])) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
                ->setJSON(['message' => 'You are not allowed to delete this patient.']);
        }

        $immunizationModel = new ImmunizationRecordModel();
        $immunizationModel->where('patient_id', $id)->delete();

        $obHistoryModel = new ObHistoryModel();
        $obHistoryModel->where('patient_id', $id)->delete();

        if (!empty($patient['user_id'])) {
            $userModel = new UserModel();
            $userModel->delete($patient['user_id']);
        }

        $patientModel->delete($id);

        // Log this action
        $logModel = new ActivityLogModel();
        $logModel->log(
            $this->getAuthUserId(),
            'patient_deleted',
            'Deleted patient: ' . ($patient['full_name'] ?? 'Unknown')
        );

        return $this->response->setJSON([
            'message' => 'Patient record deleted successfully.',
        ]);
    }
}