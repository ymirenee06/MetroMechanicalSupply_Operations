<?php
// Accounts, the audit log, and the numbers on each dashboard.

class AdminService
{
    /** First-run setup: creates the one initial Executive Officer on an empty database. */
    public static function bootstrapFirstExecutive(string $employee_id, string $fullName, string $password, string $confirm, string $companyName): void
    {
        tx(function () use ($employee_id, $fullName, $password, $confirm, $companyName) {
            if ((int)q_val('SELECT COUNT(*) FROM users') !== 0) {
                throw new UserError('Initial setup has already been completed.');
            }
            $employee_id = strtolower(trim($employee_id));
            $fullName = trim($fullName);
            if (!preg_match('/^[a-z0-9._-]{3,50}$/i', $employee_id)) {
                throw new UserError('Use 3 to 50 letters, numbers, hypen, dots, or underscores for the employee ID.');
            }
            if ($fullName === '') throw new UserError('Enter the full name.');
            $companyName = trim($companyName);
            if ($companyName === '') throw new UserError('Enter the company name.');
            if (mb_strlen($companyName) > 150) throw new UserError('Keep the company name to 150 characters or fewer.');
            if (strlen($password) < 12) throw new UserError('Use at least 12 characters for the password.');
            if ($password !== $confirm) throw new UserError('The passwords do not match.');
            q("INSERT INTO users (employee_id, password_hash, full_name, role, status, must_change_password)
               VALUES (?,?,?,?, 'Active', FALSE)",
              [$employee_id, password_hash($password, PASSWORD_DEFAULT), $fullName, 'Executive Officer']);
            $newUserId = (int)db()->lastInsertId();
            q("INSERT INTO settings (setting_key, setting_value, updated_by) VALUES ('company_name', ?, ?)", [$companyName, $newUserId]);
            audit('INITIAL_SETUP', 'users', $newUserId, null, ['role' => 'Executive Officer', 'company_name' => $companyName], $newUserId);
        });
    }

    public static function users(): array
    {
        return q_all("SELECT user_id, employee_id, full_name, role, status, failed_login_count, must_change_password, created_at FROM users ORDER BY role, full_name");
    }

    public static function createUser(array $d, int $adminId): string
    {
        foreach (['employee_id' => 'a employee_id', 'full_name' => 'the full name'] as $k => $label) {
            if (trim($d[$k] ?? '') === '') throw new UserError("Enter $label.");
        }
        if (!preg_match('/^[a-z0-9._-]{3,50}$/i', trim($d['employee_id']))) throw new UserError('employee_ids use 3 to 50 letters, numbers, dots, or underscores.');
        $temp = self::tempPassword();
        q("INSERT INTO users (employee_id, password_hash, full_name, role, must_change_password, created_by) VALUES (?,?,?,?,TRUE,?)",
          [strtolower(trim($d['employee_id'])), password_hash($temp, PASSWORD_DEFAULT), trim($d['full_name']), $d['role'] ?? 'Technician', $adminId]);
        audit('USER_CREATE', 'users', (int) db()->lastInsertId(), null, ['employee_id' => trim($d['employee_id']), 'role' => $d['role'] ?? '']);
        return $temp;
    }

    /** Unlocks the account and issues a temporary password that must be changed at next login. */
    public static function resetPassword(int $userId, int $adminId): string
    {
        if ($userId === $adminId) throw new UserError('Change your own password from My account.');
        $temp = self::tempPassword();
        q("UPDATE users SET password_hash = ?, must_change_password = TRUE, failed_login_count = 0,
                  status = IF(status = 'Locked', 'Active', status) WHERE user_id = ?", [password_hash($temp, PASSWORD_DEFAULT), $userId]);
        audit('PASSWORD_RESET', 'users', $userId);
        return $temp;
    }

    public static function setActive(int $userId, bool $active, int $adminId): void
    {
        if ($userId === $adminId) throw new UserError('You cannot deactivate your own account.');
        q("UPDATE users SET status = ?, failed_login_count = 0 WHERE user_id = ?", [$active ? 'Active' : 'Inactive', $userId]);
        audit($active ? 'USER_ACTIVATE' : 'USER_DEACTIVATE', 'users', $userId);
    }

    private static function tempPassword(): string
    {
        $words = ['Copper', 'Valve', 'Coil', 'Flare', 'Gauge', 'Drill', 'Pump', 'Relay'];
        return $words[random_int(0, 7)] . '-' . random_int(1000, 9999) . '-' . $words[random_int(0, 7)];
    }

    public static function auditLog(string $action = '', int $userId = 0): array
    {
        $sql = "SELECT a.*, u.full_name FROM audit_log a LEFT JOIN users u ON u.user_id = a.user_id WHERE 1 = 1";
        $p = [];
        if ($action !== '') { $sql .= " AND a.action = ?"; $p[] = $action; }
        if ($userId)        { $sql .= " AND a.user_id = ?"; $p[] = $userId; }
        return q_all($sql . " ORDER BY a.log_id DESC LIMIT 5000", $p);
    }

    public static function auditActions(): array
    {
        return array_column(q_all("SELECT DISTINCT action FROM audit_log ORDER BY action"), 'action');
    }

    /** The counts shown as tiles on the dashboard. */
    public static function counts(): array
    {
        $s = SalesService::summary(today());
        return [
            'pending_stock_in' => (int) q_val("SELECT COUNT(*) FROM stock_in WHERE status = 'Pending Verification'"),
            'low_stock'        => (int) q_val("SELECT COUNT(*) FROM v_item_stock WHERE stock_status <> 'Normal' AND is_archived = 0"),
            'overdue'          => (int) q_val("SELECT COUNT(*) FROM v_tool_holdings WHERE is_overdue = 1"),
            'tools_out'        => (float) q_val("SELECT COALESCE(SUM(qty_held),0) FROM v_tool_holdings"),
            'unconfirmed'      => (int) q_val("SELECT COUNT(*) FROM releases WHERE confirm_status = 'Awaiting Confirmation'"),
            'disputed'         => (int) q_val("SELECT COUNT(*) FROM releases WHERE confirm_status = 'Disputed'"),
            'incidents'        => (int) q_val("SELECT COUNT(*) FROM incident_reports WHERE status <> 'Resolved'"),
            'open_jobs'        => (int) q_val("SELECT COUNT(*) FROM jobs WHERE status = 'Open'"),
            'leftovers'        => (int) q_val("SELECT COUNT(*) FROM consumable_leftovers WHERE received_by IS NULL AND qty_returned > 0"),
            'net_today'        => $s ? (float)$s['net_sales'] : 0.0,
            'tx_today'         => $s ? (int)$s['transactions'] : 0,
        ];
    }
}
