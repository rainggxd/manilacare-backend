<?php

namespace App\Libraries;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailService
{
    /**
     * Send a plain-text/HTML email via Gmail SMTP using PHPMailer.
     * Returns true on success, false on failure (never throws — the
     * caller decides how to respond to the user either way).
     */
    public function send(string $toEmail, string $toName, string $subject, string $htmlBody): bool
    {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = getenv('EMAIL_SMTP_HOST');
            $mail->SMTPAuth   = true;
            $mail->Username   = getenv('EMAIL_SMTP_USER');
            $mail->Password   = getenv('EMAIL_SMTP_PASS');
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int) getenv('EMAIL_SMTP_PORT');

            $mail->setFrom(getenv('EMAIL_FROM_ADDRESS'), getenv('EMAIL_FROM_NAME'));
            $mail->addAddress($toEmail, $toName);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;

            $mail->send();
            return true;
        } catch (Exception $e) {
            log_message('error', 'EmailService failed: ' . $mail->ErrorInfo);
            return false;
        }
    }

    /**
     * OTP email for the "forgot password" flow.
     */
    public function sendOtp(string $toEmail, string $toName, string $otpCode): bool
    {
        $subject = 'ManilaCare Password Reset Code';
        $body = "
            <div style='font-family: sans-serif; max-width: 480px; margin: 0 auto;'>
                <h2 style='color: #1B5E20;'>ManilaCare</h2>
                <p>Hi {$toName},</p>
                <p>We received a request to reset your ManilaCare password. Use the code below to continue:</p>
                <p style='font-size: 32px; font-weight: bold; letter-spacing: 6px; color: #0B3D7A; text-align: center; padding: 16px; background: #F0F5F0; border-radius: 12px;'>{$otpCode}</p>
                <p>This code expires in 5 minutes. If you didn't request this, you can safely ignore this email.</p>
                <p style='color: #8A8578; font-size: 12px;'>ManilaCare — Community Health Information System</p>
            </div>
        ";

        return $this->send($toEmail, $toName, $subject, $body);
    }

    /**
     * OTP email for verifying an email address during patient
     * registration — confirms the address is real and reachable
     * before the record is saved.
     */
    public function sendRegistrationOtp(string $toEmail, string $otpCode): bool
    {
        $subject = 'Verify your email — ManilaCare Registration';
        $body = "
            <div style='font-family: sans-serif; max-width: 480px; margin: 0 auto;'>
                <h2 style='color: #1B5E20;'>ManilaCare</h2>
                <p>Thanks for registering with ManilaCare!</p>
                <p>Please use the code below to verify this email address and continue your registration:</p>
                <p style='font-size: 32px; font-weight: bold; letter-spacing: 6px; color: #0B3D7A; text-align: center; padding: 16px; background: #F0F5F0; border-radius: 12px;'>{$otpCode}</p>
                <p>This code expires in 5 minutes. If you didn't request this, you can safely ignore this email.</p>
                <p style='color: #8A8578; font-size: 12px;'>ManilaCare — Community Health Information System</p>
            </div>
        ";

        return $this->send($toEmail, 'there', $subject, $body);
    }
}