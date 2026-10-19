<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\AuthUser;
use App\Models\ActivityLogModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Backup and restore.
 *
 * - Master Admin: backs up / restores the WHOLE system (all centers).
 * - Admin (health center): backs up / restores ONLY their own center's
 *   patient data (patients, QR codes, immunizations, OB history,
 *   appointments, visits, medical records, prescriptions).
 *   User accounts are not part of a center backup; they are managed
 *   in User Management.
 *
 * Every backup file says what it covers ("scope"), so a center backup
 * can never be restored as a full system restore, and an Admin can
 * never restore a file that belongs to another center.
 */
class BackupController extends BaseController
{
    /**
     * Tables in a FULL backup, parent-first (matters for restore,
     * since child tables reference the earlier ones via foreign keys).
     */
    private array $fullTables = [
        'health_centers',
        'roles',
        'users',
        'patients',
        'patient_faces',
        'qr_codes',
        'immunization_records',
        'ob_history',
        'appointments',
        'visits',
        'medical_records',
        'prescriptions',
    ];

    /** Tables in a CENTER backup, parent-first. */
    private array $centerTables = [
        'patients',
        'patient_faces',
        'qr_codes',
        'immunization_records',
        'ob_history',
        'appointments',
        'visits',
        'medical_records',
        'prescriptions',
    ];

    // ------------------------------------------------------------------
    // EXPORT
    // ------------------------------------------------------------------

    public function export()
    {
        $auth = $this->authOrFail();
        if ($auth instanceof ResponseInterface) {
            return $auth;
        }

        $db = \Config\Database::connect();

        if ($auth->isRole('Master Admin')) {
            $backup = [
                'exported_at' => date('Y-m-d H:i:s'),
                'scope'       => ['type' => 'full'],
                'tables'      => [],
            ];
            foreach ($this->fullTables as $table) {
                $backup['tables'][$table] = $db->table($table)->get()->getResultArray();
            }
            $description = 'Exported a full system backup.';
            $filename    = 'manilacare_full_backup_' . date('Y-m-d_His') . '.json';
        } else {
            $centerId = (int) $auth->healthCenterId;
            $backup = [
                'exported_at' => date('Y-m-d H:i:s'),
                'scope'       => ['type' => 'center', 'health_center_id' => $centerId],
                'tables'      => $this->exportCenterTables($db, $centerId),
            ];
            $description = 'Exported a backup of health center #' . $centerId . '.';
            $filename    = 'manilacare_center' . $centerId . '_backup_' . date('Y-m-d_His') . '.json';
        }

        (new ActivityLogModel())->log($auth->id, 'backup_created', $description);

        return $this->response
            ->setHeader('Content-Type', 'application/json')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setJSON($backup);
    }

    private function exportCenterTables($db, int $centerId): array
    {
        $tables = array_fill_keys($this->centerTables, []);

        $tables['patients'] = $db->table('patients')
            ->where('health_center_id', $centerId)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        $patientIds = array_map('intval', array_column($tables['patients'], 'id'));

        $tables['appointments'] = $db->table('appointments')
            ->where('health_center_id', $centerId)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        $tables['visits'] = $db->table('visits')
            ->where('health_center_id', $centerId)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        if (!empty($patientIds)) {
            foreach (['patient_faces', 'qr_codes', 'immunization_records', 'ob_history', 'medical_records', 'prescriptions'] as $table) {
                $tables[$table] = $db->table($table)
                    ->whereIn('patient_id', $patientIds)
                    ->orderBy('id', 'ASC')
                    ->get()->getResultArray();
            }
        }

        return $tables;
    }

    // ------------------------------------------------------------------
    // RESTORE
    // ------------------------------------------------------------------

    public function restore()
    {
        $auth = $this->authOrFail();
        if ($auth instanceof ResponseInterface) {
            return $auth;
        }

        $data   = $this->request->getJSON(true);
        $tables = $data['tables'] ?? null;

        if (empty($tables) || !is_array($tables)) {
            return $this->fail(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY, 'Invalid backup file — no table data found.');
        }

        // Old backup files have no "scope"; they were always full backups.
        $scopeType = $data['scope']['type'] ?? 'full';

        if ($auth->isRole('Master Admin')) {
            if ($scopeType !== 'full') {
                return $this->fail(
                    ResponseInterface::HTTP_UNPROCESSABLE_ENTITY,
                    'This is a health center backup. Restore it from the Admin account of that center.'
                );
            }
            return $this->restoreFull($auth, $tables);
        }

        $centerId = (int) $auth->healthCenterId;

        if ($centerId <= 0) {
            return $this->fail(ResponseInterface::HTTP_FORBIDDEN, 'Your account has no health center assigned.');
        }

        if ($scopeType !== 'center' || (int) ($data['scope']['health_center_id'] ?? 0) !== $centerId) {
            return $this->fail(
                ResponseInterface::HTTP_FORBIDDEN,
                'This backup file does not belong to your health center.'
            );
        }

        return $this->restoreCenter($auth, $centerId, $tables);
    }

    /** Master Admin: replace everything with the contents of the file. */
    private function restoreFull(AuthUser $auth, array $tables)
    {
        $db = \Config\Database::connect();
        $db->transStart();

        // Delete in reverse (child-first) order
        foreach (array_reverse($this->fullTables) as $table) {
            $db->table($table)->emptyTable();
        }

        // Insert in parent-first order, only for tables present in the file
        foreach ($this->fullTables as $table) {
            $this->insertRows($db, $table, $tables[$table] ?? []);
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->fail(ResponseInterface::HTTP_INTERNAL_SERVER_ERROR, 'Restore failed — no changes were saved.');
        }

        (new ActivityLogModel())->log($auth->id, 'backup_restored', 'Restored the system from a full backup file.');

        return $this->response->setJSON(['message' => 'System restored successfully.']);
    }

    /** Admin: replace ONLY this center's patient data. */
    private function restoreCenter(AuthUser $auth, int $centerId, array $tables)
    {
        // Only the center tables are read from the file; anything else is ignored.
        $rows = [];
        foreach ($this->centerTables as $table) {
            $rows[$table] = (isset($tables[$table]) && is_array($tables[$table])) ? $tables[$table] : [];
        }

        $problem = $this->validateCenterRows($centerId, $rows);
        if ($problem !== null) {
            return $this->fail(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY, $problem);
        }

        $db = \Config\Database::connect();
        $db->transStart();

        // Delete this center's data only (children first).
        $own = '(SELECT id FROM patients WHERE health_center_id = ?)';
        $db->query("DELETE FROM medical_records WHERE patient_id IN $own", [$centerId]);
        $db->query("DELETE FROM prescriptions WHERE patient_id IN $own", [$centerId]);
        $db->query('DELETE FROM visits WHERE health_center_id = ?', [$centerId]);
        $db->query('DELETE FROM appointments WHERE health_center_id = ?', [$centerId]);
        $db->query("DELETE FROM patient_faces WHERE patient_id IN $own", [$centerId]);
        $db->query("DELETE FROM qr_codes WHERE patient_id IN $own", [$centerId]);
        $db->query("DELETE FROM ob_history WHERE patient_id IN $own", [$centerId]);
        $db->query("DELETE FROM immunization_records WHERE patient_id IN $own", [$centerId]);
        $db->query('DELETE FROM patients WHERE health_center_id = ?', [$centerId]);

        foreach ($this->centerTables as $table) {
            $this->insertRows($db, $table, $rows[$table]);
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->fail(
                ResponseInterface::HTTP_INTERNAL_SERVER_ERROR,
                'Restore failed — no changes were saved. The file may not match the current database.'
            );
        }

        (new ActivityLogModel())->log($auth->id, 'backup_restored', 'Restored health center #' . $centerId . ' from a backup file.');

        return $this->response->setJSON(['message' => 'Health center data restored successfully.']);
    }

    /**
     * Makes sure a center backup file only contains this center's data,
     * so it cannot be used to write rows into another center.
     * Returns an error message, or null if everything is fine.
     */
    private function validateCenterRows(int $centerId, array $rows): ?string
    {
        $patientIds = [];
        foreach ($rows['patients'] as $p) {
            if ((int) ($p['health_center_id'] ?? 0) !== $centerId) {
                return 'The file contains patients from another health center.';
            }
            $patientIds[(int) ($p['id'] ?? 0)] = true;
        }

        foreach (['appointments', 'visits'] as $table) {
            foreach ($rows[$table] as $r) {
                if ((int) ($r['health_center_id'] ?? 0) !== $centerId) {
                    return 'The file contains ' . $table . ' from another health center.';
                }
                if (!isset($patientIds[(int) ($r['patient_id'] ?? 0)])) {
                    return 'The file has ' . $table . ' that do not match its patients.';
                }
            }
        }

        foreach (['patient_faces', 'qr_codes', 'immunization_records', 'ob_history', 'medical_records', 'prescriptions'] as $table) {
            foreach ($rows[$table] as $r) {
                if (!isset($patientIds[(int) ($r['patient_id'] ?? 0)])) {
                    return 'The file has ' . $table . ' that do not match its patients.';
                }
            }
        }

        $visitIds = [];
        foreach ($rows['visits'] as $v) {
            $visitIds[(int) ($v['id'] ?? 0)] = true;
        }
        foreach ($rows['medical_records'] as $m) {
            if (!isset($visitIds[(int) ($m['visit_id'] ?? 0)])) {
                return 'The file has medical records that do not match its visits.';
            }
        }

        // A patient login account must not belong to a patient of another center.
        $userIds = [];
        foreach ($rows['patients'] as $p) {
            if (!empty($p['user_id'])) {
                $userIds[] = (int) $p['user_id'];
            }
        }
        if (!empty($userIds)) {
            $db = \Config\Database::connect();
            $clash = $db->table('patients')
                ->whereIn('user_id', $userIds)
                ->where('health_center_id !=', $centerId)
                ->countAllResults();
            if ($clash > 0) {
                return 'The file links a patient to an account of another health center.';
            }
        }

        return null;
    }

    /** Inserts rows in chunks and moves the id sequence past the restored ids. */
    private function insertRows($db, string $table, $rows): void
    {
        if (empty($rows) || !is_array($rows)) {
            return;
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            $db->table($table)->insertBatch($chunk);
        }

        $seqRow = $db->query("SELECT pg_get_serial_sequence('" . $table . "', 'id') AS seq")->getRowArray();

        if (!empty($seqRow['seq'])) {
            $db->query("SELECT setval('" . $seqRow['seq'] . "', COALESCE((SELECT MAX(id) FROM " . $table . "), 1))");
        }
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** Returns an AuthUser (Master Admin or Admin), or an error response. */
    private function authOrFail()
    {
        $auth = AuthUser::fromRequest($this->request);

        if (!$auth) {
            return $this->fail(ResponseInterface::HTTP_UNAUTHORIZED, 'Authentication required.');
        }

        if (!$auth->isRole('Master Admin', 'Admin')) {
            return $this->fail(ResponseInterface::HTTP_FORBIDDEN, 'You do not have permission to use backup and restore.');
        }

        return $auth;
    }

    private function fail(int $status, string $message)
    {
        return $this->response->setStatusCode($status)->setJSON(['message' => $message]);
    }
}