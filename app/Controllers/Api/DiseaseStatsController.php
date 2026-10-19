<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\AuthUser;
use App\Models\HealthCenterModel;
use App\Models\PatientModel;
use App\Models\SystemSettingModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Counts how many patients were diagnosed with each disease in the last
 * few days, per health center, and flags a possible outbreak when one
 * disease reaches the threshold.
 *
 * Only totals are returned (never patient names), so it is safe to show
 * to every logged-in role, including Patients, for awareness.
 *
 * Defaults: 50 cases within 7 days. They can be changed with the
 * system_settings keys "outbreak_threshold" and "outbreak_window_days".
 */
class DiseaseStatsController extends BaseController
{
    private const DEFAULT_THRESHOLD   = 50;
    private const DEFAULT_WINDOW_DAYS = 30;

    public function index()
    {
        $auth = AuthUser::fromRequest($this->request);
        if (!$auth) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON(['message' => 'Authentication required.']);
        }

        $settings  = (new SystemSettingModel())->getAllAsMap();
        $threshold = max(1, (int) ($settings['outbreak_threshold'] ?? self::DEFAULT_THRESHOLD));
        $days      = max(1, (int) ($settings['outbreak_window_days'] ?? self::DEFAULT_WINDOW_DAYS));

        // Which center(s) may this user see?
        //  - Master Admin: all centers
        //  - Staff / Doctor: their own center
        //  - Patient: the center they are registered in
        $centerId = null;
        if ($auth->isRole('Patient')) {
            $patient  = (new PatientModel())->find($auth->patientId() ?? 0);
            $centerId = (int) ($patient['health_center_id'] ?? 0);
        } elseif (!$auth->isRole('Master Admin')) {
            $centerId = (int) $auth->healthCenterId;
        }

        $empty = [
            'data' => [
                'window_days' => $days,
                'threshold'   => $threshold,
                'centers'     => [],
                'alerts'      => [],
            ],
        ];

        if ($centerId !== null && $centerId <= 0) {
            return $this->response->setJSON($empty);
        }

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $sql = "SELECT health_center_id,
                       LOWER(TRIM(disease_type)) AS disease_key,
                       MIN(TRIM(disease_type))   AS disease,
                       COUNT(*)                  AS cases
                FROM patients
                WHERE disease_type IS NOT NULL
                  AND TRIM(disease_type) <> ''
                  AND disease_updated_at >= ?";
        $binds = [$cutoff];

        if ($centerId !== null) {
            $sql    .= ' AND health_center_id = ?';
            $binds[] = $centerId;
        }

        $sql .= ' GROUP BY health_center_id, LOWER(TRIM(disease_type))
                  ORDER BY cases DESC, disease ASC';

        $rows = db_connect()->query($sql, $binds)->getResultArray();

        $centerNames = [];
        foreach ((new HealthCenterModel())->findAll() as $c) {
            $centerNames[(int) $c['id']] = $c['center_name'];
        }

        $centers = [];
        $alerts  = [];

        foreach ($rows as $row) {
            $cid   = (int) $row['health_center_id'];
            $cases = (int) $row['cases'];

            if (!isset($centers[$cid])) {
                $centers[$cid] = [
                    'health_center_id' => $cid,
                    'center_name'      => $centerNames[$cid] ?? 'Health Center',
                    'diseases'         => [],
                ];
            }

            $isOutbreak = $cases >= $threshold;

            $centers[$cid]['diseases'][] = [
                'disease'     => $row['disease'],
                'cases'       => $cases,
                'is_outbreak' => $isOutbreak,
            ];

            if ($isOutbreak) {
                $alerts[] = [
                    'health_center_id' => $cid,
                    'center_name'      => $centers[$cid]['center_name'],
                    'disease'          => $row['disease'],
                    'cases'            => $cases,
                ];
            }
        }

        return $this->response->setJSON([
            'data' => [
                'window_days' => $days,
                'threshold'   => $threshold,
                'centers'     => array_values($centers),
                'alerts'      => $alerts,
            ],
        ]);
    }
}