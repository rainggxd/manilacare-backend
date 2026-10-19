<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\UserModel;
use App\Libraries\EmailService;
use CodeIgniter\HTTP\ResponseInterface;
use Firebase\JWT\JWT;
use App\Libraries\PasswordPolicy;

class AuthController extends BaseController
{
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCK_MINUTES       = 15;
    private const MAX_OTP_ATTEMPTS   = 5;
    private const MAX_RESET_REQUESTS = 3;   // reset codes per user...
    private const RESET_WINDOW_MIN   = 15;  // ...within this many minutes

    public function login()
    {
        $email    = trim((string) $this->request->getJsonVar('email'));
        $password = (string) $this->request->getJsonVar('password');

        if (empty($email) || empty($password)) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_BAD_REQUEST)
                ->setJSON(['message' => 'Email and password are required.']);
        }

        $db        = \Config\Database::connect();
        $emailKey  = strtolower($email);
        $since     = date('Y-m-d H:i:s', time() - self::LOCK_MINUTES * 60);

        // Lockout: too many failed attempts in the last 15 minutes.
        $failed = $db->table('login_attempts')
            ->where('email', $emailKey)
            ->where('attempted_at >=', $since)
            ->countAllResults();

        if ($failed >= self::MAX_LOGIN_ATTEMPTS) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_TOO_MANY_REQUESTS)
                ->setJSON(['message' => 'Too many failed attempts. Please try again in ' . self::LOCK_MINUTES . ' minutes.']);
        }

        $userModel = new UserModel();
        $user = $userModel->findActiveByEmail($email);

        // Generic error message on purpose — never reveal whether the
        // email exists or the password was wrong (anti-enumeration).
        if (!$user || !password_verify($password, $user['password_hash'])) {
            $db->table('login_attempts')->insert([
                'email'        => $emailKey,
                'attempted_at' => date('Y-m-d H:i:s'),
            ]);
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON(['message' => 'Invalid email or password.']);
        }

        // Successful login: clear this email's failed attempts.
        $db->table('login_attempts')->where('email', $emailKey)->delete();

        $payload = [
            'iss' => 'ManilaCare',
            'sub' => $user['id'],
            'role' => $user['role_name'],
            'health_center_id' => $user['health_center_id'],
            'iat' => time(),
            'exp' => time() + (60 * 60), // 1 hour expiry
        ];

        $token = JWT::encode($payload, getenv('JWT_SECRET_KEY'), 'HS256');

        // Record this login so User Management can show Last Login and
        // derive an Online/Offline indicator.
        $userModel->update($user['id'], ['last_login' => date('Y-m-d H:i:s')]);

        return $this->response->setJSON([
            'token' => $token,
            'user' => [
                'id'               => $user['id'],
                'full_name'        => $user['full_name'],
                'email'            => $user['email'],
                'role'             => $user['role_name'],
                'health_center_id' => $user['health_center_id'],
            ],
        ]);
    }

    /**
     * Step 1: Request a password reset. Generates a 6-digit OTP, saves
     * it (invalidating any previous unused OTPs for this user), and
     * emails it via Gmail SMTP.
     *
     * Always returns a generic success message regardless of whether
     * the email exists — anti-enumeration, same principle as login().
     */
    public function forgotPassword()
    {
        $email = trim((string) $this->request->getJsonVar('email'));

        if (empty($email)) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_BAD_REQUEST)
                ->setJSON(['message' => 'Email is required.']);
        }

        $genericResponse = [
            'message' => 'If an account with that email exists, a reset code has been sent.',
        ];

        $userModel = new UserModel();
        $user = $userModel->where('email', $email)->first();

        if (!$user) {
            // Don't reveal that the email doesn't exist — just pretend it worked.
            return $this->response->setJSON($genericResponse);
        }

                $db = \Config\Database::connect();

        // Rate limit: only 3 reset codes per user every 15 minutes.
        // Same generic reply, so nobody can tell it was blocked.
        $recent = $db->table('password_resets')
            ->where('user_id', $user['id'])
            ->where('created_at >=', date('Y-m-d H:i:s', time() - self::RESET_WINDOW_MIN * 60))
            ->countAllResults();

        if ($recent >= self::MAX_RESET_REQUESTS) {
            return $this->response->setJSON($genericResponse);
        }

        // Invalidate any previous unused OTPs for this user
        $db->table('password_resets')
            ->where('user_id', $user['id'])
            ->where('used', false)
            ->update(['used' => true]);

        $otpCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));

        $db->table('password_resets')->insert([
            'user_id'    => $user['id'],
            'otp_code'   => $otpCode,
            'expires_at' => $expiresAt,
            'used'       => false,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $emailService = new EmailService();
        $emailService->sendOtp($user['email'], $user['full_name'], $otpCode);

        return $this->response->setJSON($genericResponse);
    }

    /**
     * Step 2: Verify the OTP code. On success, issues a short-lived
     * reset_token that Step 3 requires — this prevents someone from
     * jumping straight to resetPassword() without proving OTP knowledge.
     * Wrong codes are counted; after 5 the code is dead.
     */
    public function verifyOtp()
    {
        $email = trim((string) $this->request->getJsonVar('email'));
        $otpCode = trim((string) $this->request->getJsonVar('otp_code'));

        if (empty($email) || empty($otpCode)) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_BAD_REQUEST)
                ->setJSON(['message' => 'Email and code are required.']);
        }

        $userModel = new UserModel();
        $user = $userModel->where('email', $email)->first();

        if (!$user) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON(['message' => 'Invalid or expired code.']);
        }

        $db = \Config\Database::connect();
        // Take the newest unused, unexpired code for this user, then compare
        // it ourselves so wrong guesses can be counted.
        $resetRow = $db->table('password_resets')
            ->where('user_id', $user['id'])
            ->where('used', false)
            ->where('expires_at >=', date('Y-m-d H:i:s'))
            ->orderBy('created_at', 'DESC')
            ->get()
            ->getRowArray();

        if (!$resetRow) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON(['message' => 'Invalid or expired code.']);
        }

        if ((int) $resetRow['attempts'] >= self::MAX_OTP_ATTEMPTS) {
            $db->table('password_resets')->where('id', $resetRow['id'])->update(['used' => true]);
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_TOO_MANY_REQUESTS)
                ->setJSON(['message' => 'Too many wrong codes. Please request a new code.']);
        }

        if (!hash_equals((string) $resetRow['otp_code'], $otpCode)) {
            $db->table('password_resets')->where('id', $resetRow['id'])->set('attempts', 'attempts + 1', false)->update();
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON(['message' => 'Invalid or expired code.']);
        }

        $resetToken = bin2hex(random_bytes(32));

        $db->table('password_resets')
            ->where('id', $resetRow['id'])
            ->update(['reset_token' => $resetToken]);

        return $this->response->setJSON([
            'message'     => 'Code verified.',
            'reset_token' => $resetToken,
        ]);
    }

    /**
     * Step 3: Reset the password using the reset_token issued by
     * verifyOtp(). Marks the reset row as used so it can't be reused.
     */
    public function resetPassword()
    {
        $email = trim((string) $this->request->getJsonVar('email'));
        $resetToken = trim((string) $this->request->getJsonVar('reset_token'));
        $newPassword = (string) $this->request->getJsonVar('new_password');

        if (empty($email) || empty($resetToken) || empty($newPassword)) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_BAD_REQUEST)
                ->setJSON(['message' => 'Missing required fields.']);
        }

                $policyError = PasswordPolicy::check($newPassword);
        if ($policyError) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)
                ->setJSON(['message' => $policyError]);
        }

        $userModel = new UserModel();
        $user = $userModel->where('email', $email)->first();

        if (!$user) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON(['message' => 'Invalid or expired reset session.']);
        }

        $db = \Config\Database::connect();
        $resetRow = $db->table('password_resets')
            ->where('user_id', $user['id'])
            ->where('reset_token', $resetToken)
            ->where('used', false)
            ->where('expires_at >=', date('Y-m-d H:i:s'))
            ->get()
            ->getRowArray();

        if (!$resetRow) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON(['message' => 'Invalid or expired reset session.']);
        }

        $userModel->update($user['id'], [
            'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
        ]);

        $db->table('password_resets')
            ->where('id', $resetRow['id'])
            ->update(['used' => true]);

        return $this->response->setJSON(['message' => 'Password has been reset successfully.']);
    }
}