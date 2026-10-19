<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\EmailService;
use App\Models\PatientModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;

class EmailVerificationController extends BaseController
{
    /**
     * Send a 6-digit OTP to the given email address to confirm it's
     * real and reachable before a patient registration is finalized.
     * Invalidates any previous unverified codes for the same email.
     *
     * Stops early (no OTP is sent) if the email already belongs to an
     * account or a patient record.
     */
    public function sendOtp()
    {
        $email = trim((string) $this->request->getJsonVar('email'));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)
                ->setJSON(['message' => 'A valid email address is required.']);
        }

        // NEW: stop here if the email is already registered.
        // withDeleted() because a soft-deleted user still holds the unique email.
        $existingUser = (new UserModel())->withDeleted()->where('email', $email)->first();
        $existingPatient = (new PatientModel())->where('email', $email)->first();

        if ($existingUser || $existingPatient) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)
                ->setJSON(['message' => 'This email is already registered. Please use a different email.']);
        }

        $db = \Config\Database::connect();

        // Invalidate previous unverified codes for this email
        $db->table('email_verifications')
            ->where('email', $email)
            ->where('verified', false)
            ->delete();

        $otpCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));

        $db->table('email_verifications')->insert([
            'email'      => $email,
            'otp_code'   => $otpCode,
            'verified'   => false,
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $emailService = new EmailService();
        $sent = $emailService->sendRegistrationOtp($email, $otpCode);

        if (!$sent) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_INTERNAL_SERVER_ERROR)
                ->setJSON(['message' => 'Unable to send verification email right now.']);
        }

        return $this->response->setJSON(['message' => 'Verification code sent.']);
    }

    /**
     * Verify the OTP code for an email address. Marks the row as
     * verified — PatientController::register() checks for this
     * before allowing the record to be saved.
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

        $db = \Config\Database::connect();
        $row = $db->table('email_verifications')
            ->where('email', $email)
            ->where('otp_code', $otpCode)
            ->where('verified', false)
            ->where('expires_at >=', date('Y-m-d H:i:s'))
            ->orderBy('created_at', 'DESC')
            ->get()
            ->getRowArray();

        if (!$row) {
            return $this->response
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON(['message' => 'Invalid or expired code.']);
        }

        $db->table('email_verifications')
            ->where('id', $row['id'])
            ->update(['verified' => true]);

        return $this->response->setJSON(['message' => 'Email verified.']);
    }
}