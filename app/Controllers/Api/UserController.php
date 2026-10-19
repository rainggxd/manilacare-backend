<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\AuthUser;
use App\Models\UserModel;
use App\Models\ActivityLogModel;
use CodeIgniter\HTTP\ResponseInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Libraries\PasswordPolicy;

/**
 * User account management.
 *
 * - Master Admin: manages every account in every health center
 *   (including the Admin of each center).
 * - Admin (health center admin): manages only the Staff and Doctor
 *   accounts of their own health center.
 * - Staff: can only read the doctor list of their own center
 *   (used by the Appointments page dropdown).
 */
class UserController extends BaseController
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

    private function deny(string $message, int $status = ResponseInterface::HTTP_FORBIDDEN)
    {
        return $this->response->setStatusCode($status)->setJSON(['message' => $message]);
    }

    /**
     * Fetch one account together with its role name.
     * Pass $deleted = true to look among soft-deleted accounts.
     */
    private function findTarget($id, bool $deleted = false): ?array
    {
        $userModel = new UserModel();

        if ($deleted) {
            $userModel->onlyDeleted();
        }

        return $userModel
            ->select('users.*, roles.role_name')
            ->join('roles', 'roles.id = users.role_id')
            ->find($id);
    }

    /**
     * Master Admin may manage anyone. An Admin may only manage the
     * Staff and Doctor accounts of their own health center.
     */
    private function canManage(AuthUser $auth, array $target): bool
    {
        if ($auth->isRole('Master Admin')) {
            return true;
        }

        return $auth->isRole('Admin')
            && in_array($target['role_name'], ['Staff', 'Doctor'], true)
            && $auth->healthCenterId > 0
            && (int) $target['health_center_id'] === (int) $auth->healthCenterId;
    }

    /**
     * List accounts (never Patient-role rows, those are managed via
     * Patient Registration).
     *
     * - Master Admin: everyone, plus ?deleted=1 for the Recover view
     * - Admin: Staff and Doctors of their own center (+ ?deleted=1)
     * - Staff: only active Doctors of their own center, limited fields
     */
    public function index()
    {
        $auth = AuthUser::fromRequest($this->request);
        if (!$auth) {
            return $this->deny('Authentication required.', ResponseInterface::HTTP_UNAUTHORIZED);
        }

        $isMaster = $auth->isRole('Master Admin');
        $isAdmin  = $auth->isRole('Admin');

        if (!$isMaster && !$isAdmin && !$auth->isRole('Staff')) {
            return $this->deny('You are not allowed to view users.');
        }

        $userModel = new UserModel();

        // Staff: doctors of own center only, no email/last_login
        if (!$isMaster && !$isAdmin) {
            if ($auth->healthCenterId <= 0) {
                return $this->response->setJSON(['data' => []]);
            }

            $doctors = $userModel
                ->select('users.id, users.full_name, users.is_active, users.health_center_id, roles.role_name')
                ->join('roles', 'roles.id = users.role_id')
                ->where('roles.role_name', 'Doctor')
                ->where('users.health_center_id', $auth->healthCenterId)
                ->where('users.is_active', 1)
                ->orderBy('users.full_name', 'ASC')
                ->findAll();

            return $this->response->setJSON(['data' => $doctors]);
        }

        $wantDeleted = $this->request->getGet('deleted');

        $query = $userModel
            ->select('users.id, users.full_name, users.email, users.is_active, users.health_center_id, users.created_at, users.last_login, users.deleted_at, roles.role_name')
            ->join('roles', 'roles.id = users.role_id')
            ->orderBy('users.created_at', 'DESC');

        if ($isMaster) {
            $query->where('roles.role_name !=', 'Patient');
        } else {
            // Admin: only Staff and Doctors of their own center
            if ($auth->healthCenterId <= 0) {
                return $this->response->setJSON(['data' => []]);
            }
            $query
                ->whereIn('roles.role_name', ['Staff', 'Doctor'])
                ->where('users.health_center_id', $auth->healthCenterId);
        }

        if (!empty($wantDeleted)) {
            $query->onlyDeleted();
        }

        $users = $query->findAll();

        // Simple Online/Offline heuristic: logged in within the last
        // 15 minutes. There's no session/heartbeat tracking in this
        // capstone build, so last_login recency is the closest signal.
        $cutoff = strtotime('-15 minutes');
        foreach ($users as &$u) {
            $u['is_online'] = !empty($u['last_login']) && strtotime($u['last_login']) >= $cutoff;
        }
        unset($u);

        return $this->response->setJSON(['data' => $users]);
    }

    /**
     * Restore a soft-deleted account.
     */
    public function restore($id = null)
    {
        $auth = AuthUser::fromRequest($this->request);
        if (!$auth) {
            return $this->deny('Authentication required.', ResponseInterface::HTTP_UNAUTHORIZED);
        }

        $user = $this->findTarget($id, true);

        if (!$user) {
            return $this->deny('Deleted account not found.', ResponseInterface::HTTP_NOT_FOUND);
        }

        if (!$this->canManage($auth, $user)) {
            return $this->deny('You are not allowed to restore this account.');
        }

        $userModel = new UserModel();
        $userModel->update($id, ['deleted_at' => null]);

        $logModel = new ActivityLogModel();
        $logModel->log(
            $this->getAuthUserId(),
            'user_account_restored',
            'Restored account: ' . ($user['full_name'] ?? 'Unknown')
        );

        return $this->response->setJSON(['message' => 'Account restored successfully.']);
    }

    /**
     * Create a new account.
     *
     * - Master Admin: any role except Patient. Every role except
     *   Master Admin must be assigned to a health center.
     * - Admin: only Staff or Doctor, always in their own health center.
     */
    public function create()
    {
        $auth = AuthUser::fromRequest($this->request);
        if (!$auth || !$auth->isRole('Master Admin', 'Admin')) {
            return $this->deny('You are not allowed to create accounts.');
        }

        $data = $this->request->getJSON(true);

        $required = ['full_name', 'email', 'password', 'role_id'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return $this->deny(ucfirst(str_replace('_', ' ', $field)) . ' is required.', ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return $this->deny('Please enter a valid email address.', ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $policyError = PasswordPolicy::check((string) $data['password']);
        if ($policyError) {
            return $this->deny($policyError, ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $userModel = new UserModel();

        $role = $userModel->db->table('roles')->where('id', $data['role_id'])->get()->getRowArray();
        if (!$role) {
            return $this->deny('Selected role was not found.', ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }
        $roleName = $role['role_name'];

        if ($roleName === 'Patient') {
            return $this->deny('Patient accounts are created through Patient Registration.', ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($auth->isRole('Admin')) {
            if (!in_array($roleName, ['Staff', 'Doctor'], true)) {
                return $this->deny('You can only create Staff or Doctor accounts.');
            }
            if ($auth->healthCenterId <= 0) {
                return $this->deny('Your account is not assigned to a health center.');
            }
            $centerId = $auth->healthCenterId;
        } else {
            $centerId = !empty($data['health_center_id']) ? (int) $data['health_center_id'] : null;
            if ($roleName !== 'Master Admin' && empty($centerId)) {
                return $this->deny('Please select a health center for this account.', ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        // withDeleted(): a soft-deleted account still holds its email
        $existing = $userModel->withDeleted()->where('email', $data['email'])->first();
        if ($existing) {
            return $this->deny(
                'An account with this email already exists (it may be in the Recover Account list).',
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $userId = $userModel->insert([
            'role_id'          => $data['role_id'],
            'health_center_id' => $centerId,
            'full_name'        => $data['full_name'],
            'email'            => $data['email'],
            'password_hash'    => password_hash($data['password'], PASSWORD_BCRYPT),
            'is_active'        => 1,
        ], true);

        $logModel = new ActivityLogModel();
        $logModel->log(
            $this->getAuthUserId(),
            'user_account_created',
            'Created ' . $roleName . ' account for: ' . $data['full_name']
        );

        return $this->response
            ->setStatusCode(ResponseInterface::HTTP_CREATED)
            ->setJSON(['message' => 'Account created successfully.', 'user_id' => $userId]);
    }

    /**
     * Activate or deactivate an account.
     * Expects JSON body: { "is_active": true }
     */
    public function updateStatus($id = null)
    {
        $auth = AuthUser::fromRequest($this->request);
        if (!$auth) {
            return $this->deny('Authentication required.', ResponseInterface::HTTP_UNAUTHORIZED);
        }

        $data = $this->request->getJSON(true);

        if (!isset($data['is_active'])) {
            return $this->deny('is_active is required.', ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $this->findTarget($id);

        if (!$user) {
            return $this->deny('User not found.', ResponseInterface::HTTP_NOT_FOUND);
        }

        if (!$this->canManage($auth, $user)) {
            return $this->deny('You are not allowed to change this account.');
        }

        if (!$data['is_active'] && (int) $this->getAuthUserId() === (int) $id) {
            return $this->deny('You cannot deactivate your own account.');
        }

        $userModel = new UserModel();
        $userModel->update($id, ['is_active' => $data['is_active'] ? 1 : 0]);

        $logModel = new ActivityLogModel();
        $logModel->log(
            $this->getAuthUserId(),
            'user_status_updated',
            ($data['is_active'] ? 'Activated' : 'Deactivated') . ' account: ' . ($user['full_name'] ?? 'Unknown')
        );

        return $this->response->setJSON(['message' => 'Account status updated.']);
    }

    /**
     * Soft-delete an account. It disappears from the normal list but
     * can be brought back via restore(). A logged-in user cannot delete
     * their own account, to prevent locking yourself out.
     */
    public function delete($id = null)
    {
        $auth = AuthUser::fromRequest($this->request);
        if (!$auth) {
            return $this->deny('Authentication required.', ResponseInterface::HTTP_UNAUTHORIZED);
        }

        $authUserId = $this->getAuthUserId();
        if ($authUserId !== null && (int) $authUserId === (int) $id) {
            return $this->deny('You cannot delete your own account while logged in.');
        }

        $user = $this->findTarget($id);

        if (!$user) {
            return $this->deny('User not found.', ResponseInterface::HTTP_NOT_FOUND);
        }

        if (!$this->canManage($auth, $user)) {
            return $this->deny('You are not allowed to delete this account.');
        }

        $userModel = new UserModel();
        $userModel->delete($id);

        $logModel = new ActivityLogModel();
        $logModel->log(
            $this->getAuthUserId(),
            'user_account_deleted',
            'Deleted account: ' . ($user['full_name'] ?? 'Unknown')
        );

        return $this->response->setJSON(['message' => 'Account deleted successfully. It can be recovered from the Recover Account view.']);
    }
}