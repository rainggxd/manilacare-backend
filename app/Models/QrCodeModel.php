<?php

namespace App\Models;

use CodeIgniter\Model;

class QrCodeModel extends Model
{
    protected $table         = 'qr_codes';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'patient_id',
        'qr_token',
        'is_active',
        'created_at',
    ];

    protected $validationRules = [
        'patient_id' => 'required|is_natural_no_zero',
        'qr_token'   => 'required|max_length[64]',
    ];

    /**
     * Generates a cryptographically random, URL-safe token to embed
     * inside the QR code image. We never encode the raw patient_id
     * directly in the QR, so a scanned code can't be used to guess
     * other patient IDs.
     */
    public function generateToken(): string
    {
        return bin2hex(random_bytes(24)); // 48-character hex token
    }
}