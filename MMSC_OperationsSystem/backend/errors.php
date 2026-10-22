<?php
// Turns errors into messages staff can act on.
// The constraint names come straight from 01_schema.sql.

/** A business-rule problem with a message meant for the user. */
class UserError extends RuntimeException {}

/** In debug mode, the real error text, so problems can be diagnosed. */
function error_details(Throwable $e): string
{
    if (empty($GLOBALS['CONFIG']['debug'])) return '';
    return ' Technical details: ' . $e->getMessage();
}

function friendly_error(Throwable $e): string
{
    if ($e instanceof UserError) return $e->getMessage();
    if (!$e instanceof PDOException) {
        error_log((string)$e);
        return 'Something went wrong, so nothing was saved. Try again.' . error_details($e);
    }

    $info = $e->errorInfo ?? [];
    $code = (int)($info[1] ?? 0);
    $msg  = (string)($info[2] ?? $e->getMessage());

    // Triggers raise SQLSTATE 45000 with a message already written for people.
    if ($code === 1644) return rtrim($msg, '.') . '.';

    if ($code === 1062) {
        $dup = [
            'receipts'               => 'That OR number has already been used. Check the receipt booklet.',
            'employee_id'            => 'That employee ID is already taken.',
            'item_code'              => 'That item code already exists.',
            'job_ref_no'             => 'That job order number is already registered.',
            'barcode'                => 'That barcode is already on another item.',
            'uq_open_drawer'         => 'That drawer already has an open session.',
            'uq_open_staff'          => 'You already have a drawer open.',
            'uq_beginning_inventory' => 'This item already has a Beginning Inventory entry.',
            'uq_active_billing'      => 'This job has already been billed.',
            'uq_jt_active'           => 'That technician is already assigned to this job.',
            'release_item_id'        => 'Leftovers for that line were already declared.',
            'business_date'          => 'That business day already exists.',
        ];
        foreach ($dup as $key => $text) {
            if (str_contains($msg, $key)) return $text;
        }
        return 'That value is already used by another record.';
    }

    // 3819 = MySQL CHECK failed, 4025 = MariaDB CHECK failed
    if (in_array($code, [3819, 4025], true) && preg_match('/(chk_[a-z_]+)/', $msg, $m)) {
        $checks = [
            'chk_si_verifier'           => 'You encoded this delivery, so a different Executive Officer has to verify it.',
            'chk_si_reject_reason'      => 'Give a reason for rejecting it.',
            'chk_si_qty'                => 'The quantity received must be more than zero.',
            'chk_sales_disc_not_self'   => 'A discount cannot be approved by the person recording the sale.',
            'chk_sales_discount_reason' => 'Give a reason for the discount.',
            'chk_sales_amounts'         => 'The discount cannot be more than the sale total.',
            'chk_pay_reference'         => 'Non-cash payments need a reference number.',
            'chk_rel_not_self'          => 'You cannot release items to yourself.',
            'chk_ds_drawer'             => 'Choose drawer 1 or 2.',
            'chk_jobs_no_charge'        => 'Give a reason for No Charge.',
            'chk_jobs_cancel'           => 'Give a reason for the cancellation.',
            'chk_tr_notes'              => 'Describe the condition in the inspection notes.',
            'chk_sl_amounts'            => 'A sale line total does not match quantity × price.',
            'chk_items_money'           => 'Prices and the low-stock level cannot be negative.',
            'chk_jfc'                   => 'Give a reason for the change.',
        ];
        return $checks[$m[1]] ?? 'That entry breaks a business rule (' . $m[1] . '), so nothing was saved.';
    }

    if ($code === 1452) return 'A linked record does not exist. Refresh the page and try again.';
    if ($code === 1451) return 'Other records depend on this one, so it cannot be removed.';
    if (in_array($code, [1048, 1364], true)) return 'A required field is missing.';
    if (in_array($code, [1265, 1366], true)) return 'One of the values is not allowed.';
    if ($code === 0 && preg_match('/\[(\d{4})\]/', $e->getMessage(), $m)) $code = (int)$m[1];
    $c = $GLOBALS['CONFIG'] ?? [];
    $where = ($c['db_host'] ?? '127.0.0.1') . ':' . ($c['db_port'] ?? 3306);
    if (in_array($code, [2002, 2003, 2006], true)) {
        return "MySQL is not answering at $where. Start MySQL in the XAMPP Control Panel (or the MySQL80 service). "
             . "If the database is hosted elsewhere, check the MMSC_DB_HOST and MMSC_DB_PORT environment variables.";
    }
    if ($code === 1045) {
        return "MySQL refused the configured database login \"" . ($c['db_user'] ?? 'root') . "\". Check MMSC_DB_USER and MMSC_DB_PASS (or the local config.php).";
    }
    if ($code === 1049) {
        return "MySQL is running, but it has no database named \"" . ($c['db_name'] ?? 'mmsc_db') . "\". "
             . "Create/select the configured database, then import database/01_schema.sql into it.";
    }
    if ($code === 1146 || $code === 1356) {
        return 'Part of the database is missing (a table or view). Verify that database/01_schema.sql has been imported into the configured database.' . error_details($e);
    }
    error_log((string)$e);
    return 'The database rejected the entry, so nothing was saved.' . error_details($e);
}
