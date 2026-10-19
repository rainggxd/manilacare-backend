<?php

namespace App\Models;

use CodeIgniter\Model;

class ActivityLogModel extends Model
{
    protected $table         = 'activity_logs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'user_id',
        'action',
        'description',
    ];

    /**
     * Convenience helper other controllers call to record an event.
     * Never throws — if the actor's user_id no longer exists (e.g. the
     * account was deleted) or the insert fails for any reason, the log
     * entry is silently dropped instead of breaking the calling action.
     */
    public function log(?int $userId, string $action, string $description): void
    {
        try {
            if ($userId !== null) {
                $exists = $this->db->table('users')->where('id', $userId)->countAllResults() > 0;
                if (!$exists) {
                    $userId = null;
                }
            }

            $this->insert([
                'user_id'     => $userId,
                'action'      => $action,
                'description' => $description,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Activity log insert failed: ' . $e->getMessage());
        }
    }
}