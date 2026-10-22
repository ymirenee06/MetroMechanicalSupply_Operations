<?php
/** Escape anything printed into HTML (stops XSS). */
function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

function money($v): string { return '₱' . number_format((float)$v, 2); }

/** 12.00 -> "12", 2.50 -> "2.5" */
function qty($v): string
{
    $f = (float)$v;
    if (floor($f) == $f) return number_format($f, 0);
    return rtrim(rtrim(number_format($f, 2), '0'), '.');
}

function url(string $page, array $params = []): string
{
    return 'index.php?' . http_build_query(['page' => $page] + $params);
}

function redirect(string $page, array $params = []): never
{
    header('Location: ' . url($page, $params));
    exit;
}

function flash(string $type, string $text): void { $_SESSION['flash'][] = [$type, $text]; }

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

/**
 * Every form carries the session's CSRF token plus a one-time token (_once). The one-time token stops
 * the same form from being saved twice (double click, Enter pressed twice, a re-sent page).
 */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'
         . '<input type="hidden" name="_once" value="' . bin2hex(random_bytes(12)) . '">';
}

/** Returns false when this form's one-time token was already used in this session. */
function once_check(): bool
{
    $t = (string)($_POST['_once'] ?? '');
    if ($t === '' || !preg_match('/^[a-f0-9]{24}$/', $t)) return true;      // older forms without a token still work
    $used = $_SESSION['used_once'] ?? [];
    if (isset($used[$t])) return false;
    $used[$t] = time();
    if (count($used) > 300) $used = array_slice($used, -300, null, true);
    $_SESSION['used_once'] = $used;
    return true;
}

function csrf_check(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['_csrf'] ?? ''))) {
        http_response_code(400);
        exit('Your session expired. Go back, refresh the page, and try again.');
    }
}

function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }

/**
 * Cleans typed text: removes invisible characters (zero-width spaces, joiners, byte-order marks,
 * Hangul/Braille "blank" fillers, control characters), turns every kind of Unicode space
 * (non-breaking, ideographic, thin…) into a normal space, and trims both ends.
 * A field that only held spaces or invisible characters therefore becomes ''.
 */
function clean_text(string $s): string
{
    $out = preg_replace('/[\p{Cf}\x{115F}\x{1160}\x{3164}\x{FFA0}\x{2800}]/u', '', $s);
    if ($out === null) return trim($s);                                   // not valid UTF-8: plain trim
    $out = preg_replace('/[^\P{Cc}\n\t]/u', '', $out) ?? $out;              // control characters except newline/tab
    $out = preg_replace('/[\p{Zs}\x{2028}\x{2029}]/u', ' ', $out) ?? $out;  // all Unicode spaces → ' '
    return preg_replace('/^\s+|\s+$/u', '', $out) ?? trim($out);
}

/** True when the text has at least one letter or digit (not just spaces, dots, dashes or symbols). */
function has_text(?string $s): bool
{
    return preg_match('/[\p{L}\p{N}]/u', clean_text((string)$s)) === 1;
}

/** Smallest number of letters/digits a typed reason or note must have, so "x" or "." doesn't pass as a reason. */
const REASON_MIN = 3;

/** True when a reason/note is real text: at least REASON_MIN letters or digits after cleaning. */
function reason_ok(?string $s): bool
{
    return preg_match_all('/[\p{L}\p{N}]/u', clean_text((string)$s)) >= REASON_MIN;
}

/** Appends the shared rule to a "give a reason" message. */
function reason_msg(string $msg): string
{
    return rtrim($msg, '.') . ' (at least ' . REASON_MIN . ' letters or numbers, not just spaces or symbols).';
}

/** Cleans every submitted text field once per request (passwords and the CSRF token are left exactly as typed). */
function clean_post_input(): void
{
    $walk = function ($v, $key) use (&$walk) {
        if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = $walk($x, (string)$k); return $v; }
        if (!is_string($v) || preg_match('/password|_csrf/i', $key)) return $v;
        return clean_text($v);
    };
    foreach ($_POST as $k => $v) $_POST[$k] = $walk($v, (string)$k);
}

function post(string $key, $default = '')
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function company_name(): string
{
    static $name = null;
    if ($name !== null) return $name;
    $fallback = (string)($GLOBALS['CONFIG']['company'] ?? 'Your Company Name');
    try {
        $dbName = q_val("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
        $name = trim((string)$dbName) !== '' ? trim((string)$dbName) : $fallback;
    } catch (Throwable $e) {
        $name = $fallback;
    }
    return $name;
}

function setting(string $key, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = array_column(q_all("SELECT setting_key, setting_value FROM settings"), 'setting_value', 'setting_key');
    }
    return $cache[$key] ?? $default;
}

/** Writes one row to the permanent audit log. */
function audit(string $action, ?string $table = null, $recordId = null, ?array $old = null, ?array $new = null, ?int $userId = null): void
{
    $uid = $userId ?? (current_user()['user_id'] ?? null);
    q("INSERT INTO audit_log (user_id, action, table_name, record_id, old_values, new_values, ip_address)
       VALUES (?, ?, ?, ?, ?, ?, ?)", [
        $uid, $action, $table, $recordId,
        $old === null ? null : json_encode($old),
        $new === null ? null : json_encode($new),
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

function fmt_dt($v): string { return $v ? date('M j, Y g:i A', strtotime($v)) : '—'; }
function fmt_d($v): string  { return $v ? date('M j, Y', strtotime($v)) : '—'; }

/** Reads a number from a form value. */
function to_num($v): float
{
    $v = str_replace([',', '₱', ' '], '', (string)$v);
    if ($v === '') return 0.0;
    if (!is_numeric($v)) throw new UserError('Enter numbers only in amount and quantity fields.');
    return round((float)$v, 2);
}

function require_whole(float $q, array $item): void
{
    if (!$item['allows_decimal'] && floor($q) != $q) {
        throw new UserError($item['name'] . ' is counted in whole ' . $item['unit_of_measure'] . 's. Enter a whole number.');
    }
}

/** Collects repeated form rows like lines[0][item_id], lines[1][item_id] ... */
function post_rows(string $key): array
{
    $rows = $_POST[$key] ?? [];
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

function status_badge(?string $status): string
{
    $status = (string)$status;
    $tone = [
        'Posted' => 'ok', 'Confirmed' => 'ok', 'Completed' => 'ok', 'Returned' => 'ok', 'Billed' => 'ok',
        'Normal' => 'ok', 'Resolved' => 'ok', 'Released' => 'ok', 'Fully released' => 'ok', 'Active' => 'ok', 'Applied' => 'ok', 'Approved' => 'ok',
        'Open' => 'info', 'Out' => 'info', 'Recorded' => 'info',
        'Pending Verification' => 'warn', 'Awaiting Confirmation' => 'warn', 'Low' => 'warn',
        'Unbilled' => 'warn', 'Submitted' => 'warn', 'Partly released' => 'warn', 'Not released' => 'muted', 'Pending' => 'warn', 'Under Review' => 'warn',
        'Rejected' => 'bad', 'Disputed' => 'bad', 'Out of Stock' => 'bad', 'Voided' => 'bad',
        'Locked' => 'bad', 'Under Incident' => 'bad', 'Overdue' => 'bad', 'Denied' => 'bad', 'Declined' => 'bad',
        'Cancelled' => 'muted', 'Inactive' => 'muted', 'Hidden' => 'muted',
    ][$status] ?? 'muted';
    return '<span class="badge badge-' . $tone . '">' . e($status) . '</span>';
}

/** Today's date in the app's time zone. */
function today(): string { return date('Y-m-d'); }

/** Small coloured label so tools and consumables are easy to tell apart. */
function type_badge(?string $type, $sellable = null): string
{
    if ($type !== 'Tool/Equipment') return '<span class="type-badge type-consumable">Consumable</span>';
    if ($sellable === null) return '<span class="type-badge type-tool">Tool</span>';
    return $sellable ? '<span class="type-badge type-sale">Tool for sale</span>' : '<span class="type-badge type-tool">Company tool</span>';
}

/** Company tools are lent to technicians; tools marked "can be sold" are merchandise and are never lent. */
function is_company_tool(array $item): bool
{
    return ($item['item_type'] ?? '') === 'Tool/Equipment' && empty($item['is_sellable']);
}

/** Thumbnail for an item, or an empty placeholder box. */
function item_thumb(?string $path, string $alt = ''): string
{
    if ($path && is_file(dirname(__DIR__) . '/' . $path)) {
        return '<img class="thumb" src="' . e($path) . '" alt="' . e($alt) . '" width="48" height="48" style="width:48px;height:48px;object-fit:cover" loading="lazy">';
    }
    return '<span class="thumb-empty" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m21 16-5-5-8 8"/></svg></span>';
}

/** Quantity input attributes: whole numbers unless the item is sold in measured amounts. */
function qty_step(bool $allowsDecimal): string
{
    return $allowsDecimal ? 'step="0.01" min="0" inputmode="decimal"' : 'step="1" min="0" inputmode="numeric"';
}

/** Asset URL with a version stamp so browsers reload CSS/JS after every update (no stale cache). */
function asset(string $path): string
{
    $file = dirname(__DIR__) . '/' . $path;
    return $path . '?v=' . (is_file($file) ? filemtime($file) : '1');
}
