<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\AuthUser;
use App\Models\ActivityLogModel;
use App\Models\PatientFaceModel;
use App\Models\PatientModel;
use App\Models\SystemSettingModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Face verification for check-in.
 *
 * - The browser (face-api.js) turns a camera frame into 128 numbers.
 * - Enroll: we save those numbers for the patient (with consent).
 * - Verify: the browser sends new numbers, and WE compare them with the
 *   saved ones. The saved template never leaves the server.
 *
 * Allowed: Admin and Staff, only for patients of their own health center.
 */
class FaceController extends BaseController
{
    private const DESCRIPTOR_SIZE = 128;

    // Lower = stricter. 0.5 is a common value for face-api.js.
    private const MATCH_THRESHOLD = 0.5;

    // Finding a patient among many faces is riskier than checking one known
    // patient, so identify() is stricter and wants a clear winner.
    private const IDENTIFY_THRESHOLD = 0.45;
    private const IDENTIFY_MARGIN    = 0.05;

    /** GET api/face/enabled  (tells the registration form if a face is required) */
    public function enabled()
    {
        $auth = AuthUser::fromRequest($this->request);
        if (!$auth) {
            return $this->fail(ResponseInterface::HTTP_UNAUTHORIZED, 'Authentication required.');
        }
        if (!$auth->isRole('Admin', 'Staff')) {
            return $this->fail(ResponseInterface::HTTP_FORBIDDEN, 'You are not allowed to do this.');
        }

        return $this->response->setJSON(['data' => ['enabled' => $this->isEnabled()]]);
    }

    /** GET api/patients/{id}/face */
    public function status($patientId = null)
    {
        [$auth, $patient, $error] = $this->resolve((int) $patientId);
        if ($error) {
            return $error;
        }

        $row = (new PatientFaceModel())->where('patient_id', $patient['id'])->first();

        return $this->response->setJSON([
            'data' => [
                'enabled'     => $this->isEnabled(),
                'enrolled'    => $row !== null,
                'enrolled_at' => $row['updated_at'] ?? ($row['created_at'] ?? null),
            ],
        ]);
    }

    /** POST api/patients/{id}/face   body: { descriptor: [128 numbers], consent: true } */
    public function enroll($patientId = null)
    {
        [$auth, $patient, $error] = $this->resolve((int) $patientId);
        if ($error) {
            return $error;
        }

        if (!$this->isEnabled()) {
            return $this->fail(ResponseInterface::HTTP_FORBIDDEN, 'Facial recognition is turned off.');
        }

        $data = $this->request->getJSON(true) ?? [];

        if (empty($data['consent']) || $data['consent'] !== true) {
            return $this->fail(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY, 'Patient consent is required before saving a face.');
        }

        $descriptor = $this->cleanDescriptor($data['descriptor'] ?? null);
        if ($descriptor === null) {
            return $this->fail(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY, 'The face data is not valid. Please capture again.');
        }

        $model = new PatientFaceModel();
        $now   = date('Y-m-d H:i:s');
        $row   = $model->where('patient_id', $patient['id'])->first();

        $payload = [
            'descriptor'  => json_encode($descriptor),
            'consent_at'  => $now,
            'enrolled_by' => (int) $auth->id,
            'updated_at'  => $now,
        ];

        if ($row) {
            $model->update($row['id'], $payload);
            $action = 'face_updated';
            $text   = 'Updated the face data of patient: ';
        } else {
            $payload['patient_id'] = (int) $patient['id'];
            $payload['created_at'] = $now;
            $model->insert($payload);
            $action = 'face_enrolled';
            $text   = 'Enrolled the face of patient: ';
        }

        (new ActivityLogModel())->log((int) $auth->id, $action, $text . $patient['full_name']);

        return $this->response->setJSON(['message' => 'Face saved successfully.']);
    }

    /** POST api/patients/{id}/face/verify   body: { descriptor: [128 numbers] } */
    public function verify($patientId = null)
    {
        [$auth, $patient, $error] = $this->resolve((int) $patientId);
        if ($error) {
            return $error;
        }

        if (!$this->isEnabled()) {
            return $this->fail(ResponseInterface::HTTP_FORBIDDEN, 'Facial recognition is turned off.');
        }

        $data       = $this->request->getJSON(true) ?? [];
        $descriptor = $this->cleanDescriptor($data['descriptor'] ?? null);
        if ($descriptor === null) {
            return $this->fail(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY, 'The face data is not valid. Please capture again.');
        }

        $row = (new PatientFaceModel())->where('patient_id', $patient['id'])->first();
        if (!$row) {
            return $this->fail(ResponseInterface::HTTP_NOT_FOUND, 'This patient has no face on file.');
        }

        $stored = json_decode($row['descriptor'], true);
        if (!is_array($stored) || count($stored) !== self::DESCRIPTOR_SIZE) {
            return $this->fail(ResponseInterface::HTTP_INTERNAL_SERVER_ERROR, 'Saved face data is damaged. Please enroll the face again.');
        }

        $sum = 0.0;
        for ($i = 0; $i < self::DESCRIPTOR_SIZE; $i++) {
            $diff = (float) $stored[$i] - $descriptor[$i];
            $sum += $diff * $diff;
        }
        $distance = sqrt($sum);
        $match    = $distance < self::MATCH_THRESHOLD;

        (new ActivityLogModel())->log(
            (int) $auth->id,
            $match ? 'face_verified' : 'face_mismatch',
            ($match ? 'Face matched for patient: ' : 'Face did NOT match for patient: ') . $patient['full_name']
        );

        return $this->response->setJSON([
            'data' => [
                'match'    => $match,
                'distance' => round($distance, 3),
            ],
        ]);
    }

    /**
     * POST api/face/identify   body: { descriptor: [128 numbers] }
     * Finds the patient (of the user's own health center) whose saved face
     * is closest to the one sent. It only SUGGESTS a patient. Staff must
     * confirm on screen before anything else happens.
     */
    public function identify()
    {
        $auth = AuthUser::fromRequest($this->request);
        if (!$auth) {
            return $this->fail(ResponseInterface::HTTP_UNAUTHORIZED, 'Authentication required.');
        }
        if (!$auth->isRole('Admin', 'Staff')) {
            return $this->fail(ResponseInterface::HTTP_FORBIDDEN, 'You are not allowed to do this.');
        }

        $centerId = (int) $auth->healthCenterId;
        if ($centerId <= 0) {
            return $this->fail(ResponseInterface::HTTP_FORBIDDEN, 'Your account has no health center assigned.');
        }

        if (!$this->isEnabled()) {
            return $this->fail(ResponseInterface::HTTP_FORBIDDEN, 'Facial recognition is turned off.');
        }

        $data       = $this->request->getJSON(true) ?? [];
        $descriptor = $this->cleanDescriptor($data['descriptor'] ?? null);
        if ($descriptor === null) {
            return $this->fail(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY, 'The face data is not valid. Please capture again.');
        }

        $rows = (new PatientFaceModel())
            ->select('patient_faces.patient_id, patient_faces.descriptor, patients.full_name, patients.family_number')
            ->join('patients', 'patients.id = patient_faces.patient_id')
            ->where('patients.health_center_id', $centerId)
            ->findAll();

        $best   = null;
        $second = null;
        foreach ($rows as $row) {
            $stored = json_decode($row['descriptor'], true);
            if (!is_array($stored) || count($stored) !== self::DESCRIPTOR_SIZE) {
                continue;
            }
            $sum = 0.0;
            for ($i = 0; $i < self::DESCRIPTOR_SIZE; $i++) {
                $diff = (float) $stored[$i] - $descriptor[$i];
                $sum += $diff * $diff;
            }
            $distance = sqrt($sum);

            if ($best === null || $distance < $best['distance']) {
                $second = $best;
                $best   = ['row' => $row, 'distance' => $distance];
            } elseif ($second === null || $distance < $second['distance']) {
                $second = ['row' => $row, 'distance' => $distance];
            }
        }

        $log = new ActivityLogModel();

        $clear = $best !== null
            && $best['distance'] < self::IDENTIFY_THRESHOLD
            && ($second === null || ($second['distance'] - $best['distance']) >= self::IDENTIFY_MARGIN);

        if (!$clear) {
            $log->log((int) $auth->id, 'face_identify_none', 'Face scan found no clear match.');
            return $this->response->setJSON([
                'data' => ['found' => false, 'enrolled_count' => count($rows)],
            ]);
        }

        $log->log(
            (int) $auth->id,
            'face_identify',
            'Face scan suggested patient: ' . $best['row']['full_name']
        );

        return $this->response->setJSON([
            'data' => [
                'found'    => true,
                'distance' => round($best['distance'], 3),
                'patient'  => [
                    'id'            => (int) $best['row']['patient_id'],
                    'full_name'     => $best['row']['full_name'],
                    'family_number' => $best['row']['family_number'],
                ],
            ],
        ]);
    }

    /**
     * POST api/patients/{id}/face/override   body: { reason: "..." }
     * Staff proceeds without a face match. This only writes an audit log entry.
     */
    public function override($patientId = null)
    {
        [$auth, $patient, $error] = $this->resolve((int) $patientId);
        if ($error) {
            return $error;
        }

        $data   = $this->request->getJSON(true) ?? [];
        $reason = trim((string) ($data['reason'] ?? ''));

        if (strlen($reason) < 3) {
            return $this->fail(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY, 'Please give a reason.');
        }

        $reason = mb_substr($reason, 0, 200);

        (new ActivityLogModel())->log(
            (int) $auth->id,
            'face_override',
            'Checked in without a face match for patient: ' . $patient['full_name'] . '. Reason: ' . $reason
        );

        return $this->response->setJSON(['message' => 'Override recorded.']);
    }

    /** DELETE api/patients/{id}/face   (Admin only, set in Routes.php) */
    public function delete($patientId = null)
    {
        [$auth, $patient, $error] = $this->resolve((int) $patientId);
        if ($error) {
            return $error;
        }

        (new PatientFaceModel())->where('patient_id', $patient['id'])->delete();

        (new ActivityLogModel())->log(
            (int) $auth->id,
            'face_deleted',
            'Removed the face data of patient: ' . $patient['full_name']
        );

        return $this->response->setJSON(['message' => 'Face data removed.']);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** Returns [auth, patient, errorResponse]. errorResponse is null when all is fine. */
    private function resolve(int $patientId): array
    {
        $auth = AuthUser::fromRequest($this->request);
        if (!$auth) {
            return [null, null, $this->fail(ResponseInterface::HTTP_UNAUTHORIZED, 'Authentication required.')];
        }

        if (!$auth->isRole('Admin', 'Staff')) {
            return [null, null, $this->fail(ResponseInterface::HTTP_FORBIDDEN, 'You are not allowed to do this.')];
        }

        $patient = (new PatientModel())->select('id, full_name, health_center_id')->find($patientId);
        if (!$patient) {
            return [null, null, $this->fail(ResponseInterface::HTTP_NOT_FOUND, 'Patient not found.')];
        }

        if (!$auth->canAccessCenter($patient['health_center_id'])) {
            return [null, null, $this->fail(ResponseInterface::HTTP_FORBIDDEN, 'This patient belongs to another health center.')];
        }

        return [$auth, $patient, null];
    }

    private function isEnabled(): bool
    {
        $settings = (new SystemSettingModel())->getAllAsMap();
        return ($settings['facial_recognition_enabled'] ?? '1') === '1';
    }

    /** Returns a clean array of 128 floats, or null if the input is not valid. */
    private function cleanDescriptor($raw): ?array
    {
        if (!is_array($raw) || count($raw) !== self::DESCRIPTOR_SIZE) {
            return null;
        }

        $out  = [];
        $norm = 0.0;
        foreach (array_values($raw) as $value) {
            if (!is_int($value) && !is_float($value)) {
                return null;
            }
            $value = (float) $value;
            if (!is_finite($value) || abs($value) > 5) {
                return null;
            }
            $out[] = round($value, 6);
            $norm += $value * $value;
        }

        // An all-zero (or almost empty) template is not a real face.
        if (sqrt($norm) < 0.1) {
            return null;
        }

        return $out;
    }

    private function fail(int $status, string $message)
    {
        return $this->response->setStatusCode($status)->setJSON(['message' => $message]);
    }
}