<?php
// Login, logout, lockout, roles, and password changes.

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) return $user;
    $id = $_SESSION['user_id'] ?? null;
    $user = $id ? q_one("SELECT user_id, employee_id, full_name, role, status, must_change_password, password_hash FROM users WHERE user_id = ?", [$id]) : null;
    if ($user) {
        // Password-hash fingerprint lets password reset/change invalidate older sessions
        // without storing the password hash or raw password in the session.
        $fingerprint = hash('sha256', (string)$user['password_hash']);
        unset($user['password_hash']);
        $sessionFingerprint = (string)($_SESSION['auth_fingerprint'] ?? '');
        if ($user['status'] !== 'Active' || $sessionFingerprint === '' || !hash_equals($fingerprint, $sessionFingerprint)) {
            $_SESSION = [];
            $user = null;
        }
    }
    return $user;
}

function uid(): int { return (int)current_user()['user_id']; }

function has_role(string ...$roles): bool
{
    $u = current_user();
    return $u && in_array($u['role'], $roles, true);
}

/**
 * Checks a employee id and password. Five wrong tries in a row locks the account.
 * Returns the user row on success; throws UserError otherwise.
 */
function attempt_login(string $employee_id, string $password): array
{
    $u = q_one("SELECT * FROM users WHERE employee_id = ?", [$employee_id]);
    $limit = (int) setting('failed_login_limit', 5);

    if (!$u) {
        audit('LOGIN_FAILED', null, null, null, ['employee_id_tried' => $employee_id], null);
        throw new UserError('Wrong employee_id or password.');
    }
    if ($u['status'] === 'Inactive') throw new UserError('This account has been deactivated. Ask an Executive Officer.');
    if ($u['status'] === 'Locked')   throw new UserError('This account is locked after too many wrong passwords. Ask an Executive Officer to unlock it.');

    if (!password_verify($password, $u['password_hash'])) {
        $fails = (int)$u['failed_login_count'] + 1;
        $lock  = $fails >= $limit;
        q("UPDATE users SET failed_login_count = ?, status = ? WHERE user_id = ?", [$fails, $lock ? 'Locked' : 'Active', $u['user_id']]);
        audit($lock ? 'LOCKOUT' : 'LOGIN_FAILED', 'users', $u['user_id'], null, ['failed_count' => $fails], (int)$u['user_id']);
        if ($lock) throw new UserError('Too many wrong passwords. The account is now locked; ask an Executive Officer to unlock it.');
        $left = $limit - $fails;
        throw new UserError("Wrong employee ID  or password. $left tr" . ($left === 1 ? 'y' : 'ies') . ' left before the account locks.');
    }

    q("UPDATE users SET failed_login_count = 0 WHERE user_id = ?", [$u['user_id']]);
    session_regenerate_id(true);           // prevents session fixation
    $_SESSION['user_id'] = (int)$u['user_id'];
    $_SESSION['auth_fingerprint'] = hash('sha256', (string)$u['password_hash']);
    audit('LOGIN', 'users', $u['user_id'], null, null, (int)$u['user_id']);
    return $u;
}

function logout(): void
{
    if (current_user()) audit('LOGOUT', 'users', uid());
    $_SESSION = [];
    session_destroy();
}

function change_password(int $userId, string $current, string $new, string $confirm): void
{
    $u = q_one("SELECT password_hash FROM users WHERE user_id = ?", [$userId]);
    if (!password_verify($current, $u['password_hash'])) throw new UserError('Your current password is wrong.');
    if (strlen($new) < 12) throw new UserError('Use at least 12 characters for the new password.');
    if ($new !== $confirm) throw new UserError('The new passwords do not match.');
    if (password_verify($new, $u['password_hash'])) throw new UserError('Choose a password different from the current one.');
    $newHash = password_hash($new, PASSWORD_DEFAULT);
    q("UPDATE users SET password_hash = ?, must_change_password = FALSE WHERE user_id = ?", [$newHash, $userId]);
    $_SESSION['auth_fingerprint'] = hash('sha256', $newHash);
    audit('PASSWORD_CHANGE', 'users', $userId);
}

/** Checks an Executive Officer's credentials typed on someone else's screen (for discount approval). */
function verify_admin(string $employee_id, string $password): array
{
    $a = q_one("SELECT * FROM users WHERE employee_id = ? AND role = 'Executive Officer' AND status = 'Active'", [$employee_id]);
    if (!$a || !password_verify($password, $a['password_hash'])) {
        throw new UserError('The Executive Officer employee ID or password for the approval is wrong.');
    }
    return $a;
}
