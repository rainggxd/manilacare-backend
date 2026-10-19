<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Libraries\PasswordPolicy;

class ProfileController extends BaseController
{
    /**
     * Decode the Bearer token from the Authorization header and
     * return the user id (the "sub" claim), or null if missing/invalid.
     * NOTE: This is a stand-in for a proper AuthFilter — same pattern
     * used across ManilaCare until a shared filter is added.
     */
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
     * Update the logged-in user's own full name and/or email.
     */
    public function updateProfile()
    {
        $userId = $this->getAuthUserId();

        if (!$userId) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON(['message' => 'Invalid or missing token.']);
        }

        $data = $this->request->getJSON(true);

        $userModel = new UserModel();
        $user = $userModel->find($userId);

        if (!$user) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_NOT_FOUND)
                ->setJSON(['message' => 'User not found.']);
        }

        $updateData = [];
        if (!empty($data['full_name'])) {
            $updateData['full_name'] = $data['full_name'];
        }
        if (!empty($data['email'])) {
            $updateData['email'] = $data['email'];
        }

        if (empty($updateData)) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)
                ->setJSON(['message' => 'Nothing to update.']);
        }

        $userModel->update($userId, $updateData);
        $updatedUser = $userModel->find($userId);

        return $this->response->setJSON([
            'message' => 'Profile updated successfully.',
            'user' => [
                'id'               => $updatedUser['id'],
                'full_name'        => $updatedUser['full_name'],
                'email'            => $updatedUser['email'],
                'role'             => $updatedUser['role_name'] ?? $data['role'] ?? null,
                'health_center_id' => $updatedUser['health_center_id'],
            ],
        ]);
    }

    /**
     * Change the logged-in user's own password.
     * Expects JSON body: { "current_password": "...", "new_password": "..." }
     */
    public function changePassword()
    {
        $userId = $this->getAuthUserId();

        if (!$userId) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON(['message' => 'Invalid or missing token.']);
        }

        $data = $this->request->getJSON(true);
        $currentPassword = $data['current_password'] ?? '';
        $newPassword = $data['new_password'] ?? '';

        if (empty($currentPassword) || empty($newPassword)) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)
                ->setJSON(['message' => 'Current and new password are required.']);
        }

                $policyError = PasswordPolicy::check($newPassword);
        if ($policyError) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)
                ->setJSON(['message' => $policyError]);
        }

        $userModel = new UserModel();
        $user = $userModel->find($userId);

        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON(['message' => 'Current password is incorrect.']);
        }

        $userModel->update($userId, [
            'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
        ]);

        return $this->response->setJSON(['message' => 'Password changed successfully.']);
    }
}