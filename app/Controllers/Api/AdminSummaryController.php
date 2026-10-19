<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\AppointmentModel;
use App\Models\HealthCenterModel;
use App\Models\PatientModel;
use App\Models\UserModel;

/**
 * Numbers for the Master Admin dashboard and Health Centers page.
 *
 * The Master Admin manages accounts and health centers but does NOT
 * see patient records, so this returns counts only (never names or
 * any other patient details).
 */
class AdminSummaryController extends BaseController
{
    public function index()
    {
        $centers = (new HealthCenterModel())->orderBy('id', 'ASC')->findAll();

        $patientCounts = [];
        foreach ((new PatientModel())->select('health_center_id, COUNT(*) AS c')->groupBy('health_center_id')->findAll() as $row) {
            $patientCounts[(int) $row['health_center_id']] = (int) $row['c'];
        }

        $appointmentCounts = [];
        foreach ((new AppointmentModel())->select('health_center_id, COUNT(*) AS c')->groupBy('health_center_id')->findAll() as $row) {
            $appointmentCounts[(int) $row['health_center_id']] = (int) $row['c'];
        }

        // Accounts per center and role (Admin, Staff, Doctor)
        $accountCounts = [];
        $userRows = (new UserModel())
            ->select('users.health_center_id, roles.role_name, COUNT(*) AS c')
            ->join('roles', 'roles.id = users.role_id')
            ->whereIn('roles.role_name', ['Admin', 'Staff', 'Doctor'])
            ->groupBy('users.health_center_id, roles.role_name')
            ->findAll();
        foreach ($userRows as $row) {
            $accountCounts[(int) $row['health_center_id']][$row['role_name']] = (int) $row['c'];
        }

        $totals = ['health_centers' => count($centers), 'patients' => 0, 'appointments' => 0, 'admins' => 0, 'staff' => 0, 'doctors' => 0];
        $result = [];

        foreach ($centers as $c) {
            $id = (int) $c['id'];

            $item = [
                'id'             => $id,
                'center_name'    => $c['center_name'],
                'address'        => $c['address'] ?? null,
                'contact_number' => $c['contact_number'] ?? null,
                'patients'       => $patientCounts[$id] ?? 0,
                'appointments'   => $appointmentCounts[$id] ?? 0,
                'admins'         => $accountCounts[$id]['Admin'] ?? 0,
                'staff'          => $accountCounts[$id]['Staff'] ?? 0,
                'doctors'        => $accountCounts[$id]['Doctor'] ?? 0,
            ];

            $totals['patients']     += $item['patients'];
            $totals['appointments'] += $item['appointments'];
            $totals['admins']       += $item['admins'];
            $totals['staff']        += $item['staff'];
            $totals['doctors']      += $item['doctors'];

            $result[] = $item;
        }

        return $this->response->setJSON(['data' => ['totals' => $totals, 'centers' => $result]]);
    }
}