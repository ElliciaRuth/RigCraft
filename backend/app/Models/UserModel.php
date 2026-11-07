<?php

namespace App\Models;

use CodeIgniter\Model;

/** Staff accounts for the console. */
class UserModel extends Model
{
    protected $table         = 'users';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['username', 'full_name', 'password_hash', 'role', 'is_active', 'last_login_at'];

    /** Every account with its activity counts, for the admin Users page. */
    public function listWithActivity(): array
    {
        $rows = $this->db->query(
            'SELECT u.id, u.username, u.full_name, u.role, u.is_active, u.last_login_at, u.created_at,
                    (SELECT COUNT(*) FROM diagnostic_logs d WHERE d.technician_id = u.id) AS tests_run,
                    (SELECT COUNT(*) FROM components c WHERE c.created_by = u.id)        AS units_logged
             FROM users u
             ORDER BY u.is_active DESC, u.role, u.full_name'
        )->getResultArray();

        foreach ($rows as &$r) {
            $r['id']           = (int) $r['id'];
            $r['is_active']    = (bool) $r['is_active'];
            $r['tests_run']    = (int) $r['tests_run'];
            $r['units_logged'] = (int) $r['units_logged'];
        }

        return $rows;
    }

    public function activeAdminCount(): int
    {
        return $this->where(['role' => 'admin', 'is_active' => 1])->countAllResults();
    }
}
