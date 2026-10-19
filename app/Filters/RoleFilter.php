<?php

namespace App\Filters;

use App\Libraries\Cors;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Backend role check. Usage in Routes.php:
 *   ['filter' => 'role:Master Admin']          -> only Master Admin
 *   ['filter' => 'role:Master Admin,Staff']    -> either role
 *   ['filter' => 'role']                       -> any logged-in user
 *   ['filter' => 'role:Master Admin,strict']   -> same check (kept so old routes still work)
 *
 * The role check is ALWAYS on. It cannot be switched off from Settings.
 *
 * Besides checking the token, it also looks the account up in the database
 * on every request. So a deactivated or deleted account, or one whose role
 * was changed, stops working right away instead of staying valid until the
 * 1-hour token runs out.
 */
class RoleFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // Let CORS preflight requests through (they carry no token).
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return;
        }

        $authHeader = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return $this->deny(401, 'Authentication required.');
        }

        try {
            $payload = JWT::decode($matches[1], new Key(getenv('JWT_SECRET_KEY'), 'HS256'));
        } catch (\Throwable $e) {
            return $this->deny(401, 'Invalid or expired token.');
        }

        // The account must still exist, be active, and still have the same role.
        $account = db_connect()
            ->table('users')
            ->select('users.id, roles.role_name')
            ->join('roles', 'roles.id = users.role_id')
            ->where('users.id', (int) ($payload->sub ?? 0))
            ->where('users.is_active', 1)
            ->where('users.deleted_at', null)
            ->get()
            ->getRowArray();

        if (!$account || $account['role_name'] !== ($payload->role ?? '')) {
            return $this->deny(401, 'Invalid or expired token.');
        }

        $arguments    = array_map('trim', (array) $arguments);
        $allowedRoles = array_values(array_diff($arguments, ['strict']));

        // No roles listed = any logged-in user is allowed.
        if (empty($allowedRoles)) {
            return;
        }

        if (!in_array($account['role_name'], $allowedRoles, true)) {
            return $this->deny(403, 'You do not have permission to access this resource.');
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // nothing to do
    }

    private function deny(int $status, string $message): ResponseInterface
    {
        $response = Services::response()
            ->setStatusCode($status)
            ->setJSON(['message' => $message]);

        // Without these, the browser shows a CORS error instead of the real 401/403.
        return Cors::apply($response, Services::request()->getHeaderLine('Origin'));
    }
}