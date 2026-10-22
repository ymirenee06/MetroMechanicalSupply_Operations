<?php
// Objective 3: every peso is tied to an OR, a drawer session, a person, and a time.

class SalesService
{
    // ---------------------------------------------------------- days and drawers

    /** Today's business day, created on first use. */
    public static function today(): array
    {
        $d = q_one("SELECT * FROM sales_days WHERE business_date = ?", [today()]);
        if (!$d) {
            q("INSERT IGNORE INTO sales_days (business_date) VALUES (?)", [today()]);
            $d = q_one("SELECT * FROM sales_days WHERE business_date = ?", [today()]);
        }
        return $d;
    }

    public static function mySession(int $uid): ?array
    {
        return q_one("SELECT ds.*, d.business_date, d.status AS day_status FROM drawer_sessions ds
                      JOIN sales_days d ON d.sales_day_id = ds.sales_day_id
                      WHERE ds.sales_staff_id = ? AND ds.closed_at IS NULL", [$uid]);
    }

    public static function requireSession(int $uid): array
    {
        $s = self::mySession($uid);
        if (!$s) throw new UserError('Open a cash drawer first.');
        if ($s['day_status'] !== 'Open') throw new UserError('This business day is closed.');
        return $s;
    }

    public static function openSessions(): array
    {
        return q_all("SELECT ds.*, u.full_name FROM drawer_sessions ds JOIN users u ON u.user_id = ds.sales_staff_id
                      WHERE ds.closed_at IS NULL ORDER BY ds.drawer_no");
    }

    /** Flow 3.1 */
    public static function openDrawer(int $drawerNo, float $float, int $uid): int
    {
        $day = self::today();
        if ($day['status'] !== 'Open') throw new UserError('Today has already been closed. Drawers cannot be opened again.');
        if (!in_array($drawerNo, [1, 2], true)) throw new UserError('Choose drawer 1 or 2.');
        if ($float < 0) throw new UserError('The opening float cannot be negative.');
        q("INSERT INTO drawer_sessions (sales_day_id, drawer_no, sales_staff_id, opening_float) VALUES (?,?,?,?)",
          [$day['sales_day_id'], $drawerNo, $uid, $float]);
        $id = (int) db()->lastInsertId();
        audit('DRAWER_OPEN', 'drawer_sessions', $id, null, ['drawer' => $drawerNo, 'float' => $float]);
        return $id;
    }

    public static function sessionCash(int $sessionId): array
    {
        return q_one("SELECT * FROM v_session_cash WHERE session_id = ?", [$sessionId]);
    }

    /** Flow 3.8: count the drawer. Any difference needs an explanation. */
    public static function closeDrawer(int $uid, float $counted, string $why): array
    {
        return tx(function () use ($uid, $counted, $why) {
            $s = self::mySession($uid);
            if (!$s) throw new UserError('You have no open drawer.');
            $expected = (float) self::sessionCash((int)$s['session_id'])['expected_cash'];
            $diff = round($counted - $expected, 2);
            if ($diff != 0 && !reason_ok($why)) throw new UserError(reason_msg('The count is ' . ($diff < 0 ? 'short' : 'over') . ' by ' . money(abs($diff)) . '. Explain the difference to close.'));
            q("UPDATE drawer_sessions SET expected_cash = ?, counted_cash = ?, difference = ?, difference_explanation = ?,
                      close_type = 'End of Day', closed_at = NOW() WHERE session_id = ?",
              [$expected, $counted, $diff, $why ?: null, $s['session_id']]);
            audit('DRAWER_CLOSE', 'drawer_sessions', $s['session_id'], null, ['expected' => $expected, 'counted' => $counted, 'difference' => $diff]);
            return ['expected' => $expected, 'difference' => $diff];
        });
    }

    public static function closeDay(int $dayId, int $adminId): void
    {
        $open = (int) q_val("SELECT COUNT(*) FROM drawer_sessions WHERE sales_day_id = ? AND closed_at IS NULL", [$dayId]);
        if ($open > 0) throw new UserError("$open drawer" . ($open > 1 ? 's are' : ' is') . ' still open. Every drawer must be counted first.');
        $pendingVoid = (int) q_val("SELECT COUNT(*) FROM void_requests vr JOIN sales s ON s.sale_id=vr.sale_id JOIN drawer_sessions ds ON ds.session_id=s.session_id WHERE ds.sales_day_id=? AND vr.status='Pending'", [$dayId]);
        $pendingReturn = (int) q_val("SELECT COUNT(*) FROM sales_returns sr JOIN sales s ON s.sale_id=sr.sale_id JOIN drawer_sessions ds ON ds.session_id=s.session_id WHERE ds.sales_day_id=? AND sr.status='Pending'", [$dayId]);
        $discountLimit=(float)setting('discount_limit_pct',5);
        $pendingDiscount = (int) q_val("SELECT COUNT(*) FROM sales WHERE session_id IN (SELECT session_id FROM drawer_sessions WHERE sales_day_id=?) AND discount_amount > gross_amount * ? / 100 AND discount_approved_by IS NULL AND status <> 'Voided'", [$dayId,$discountLimit]);
        if ($pendingVoid || $pendingReturn || $pendingDiscount) throw new UserError("The sales day cannot close while $pendingVoid void request(s), $pendingReturn sales return(s), or $pendingDiscount discount approval(s) are pending.");
        q("UPDATE sales_days SET status = 'Closed', closed_at = NOW(), reviewed_by = ? WHERE sales_day_id = ? AND status = 'Open'", [$adminId, $dayId]);
        audit('DAY_CLOSE', 'sales_days', $dayId);
    }

    // ---------------------------------------------------------- shared pieces

    /** Registers the OR number. The receipts table makes reuse impossible. */
    private static function useReceipt(string $or, string $for): string
    {
        $or = strtoupper(trim($or));
        if ($or === '') throw new UserError('Enter the OR number from the receipt booklet.');
        q("INSERT INTO receipts (or_number, receipt_for) VALUES (?,?)", [$or, $for]);
        return $or;
    }

    /** Reads payment rows, checks them against the amount due, and works out the change. */
    public static function parsePayments(array $rows, float $due): array
    {
        $pays = [];
        foreach ($rows as $r) {
            $amt = to_num($r['amount'] ?? 0);
            if ($amt <= 0) continue;
            $method = in_array($r['method'] ?? '', ['Cash', 'GCash', 'Bank Transfer', 'Check'], true) ? $r['method'] : 'Cash';
            $ref = trim($r['reference_no'] ?? '');
            if ($method !== 'Cash' && $ref === '') throw new UserError("Enter the reference number for the $method payment.");
            $pays[] = ['method' => $method, 'amount' => $amt, 'reference_no' => $ref ?: null];
        }
        $paid = array_sum(array_column($pays, 'amount'));
        $cash = array_sum(array_map(fn($p) => $p['method'] === 'Cash' ? $p['amount'] : 0, $pays));
        if ($due <= 0 && $pays) throw new UserError('Nothing is due, so remove the payment lines.');
        if ($paid + 0.001 < $due) throw new UserError('Payment is short by ' . money($due - $paid) . '.');
        $change = round($paid - $due, 2);
        if ($change > $cash + 0.001) throw new UserError('Change can only be given from cash. Non-cash payments must not exceed the amount due.');
        return [$pays, $change];
    }

    private static function insertPayments(array $pays, ?int $saleId, ?int $dpId): void
    {
        foreach ($pays as $p) {
            q("INSERT INTO payments (sale_id, downpayment_id, method, amount, reference_no) VALUES (?,?,?,?,?)",
              [$saleId, $dpId, $p['method'], $p['amount'], $p['reference_no']]);
        }
    }

    private static function nextRef(int $saleId): string
    {
        $ref = sprintf('SI-%06d', $saleId);
        q("UPDATE sales SET sale_ref_no = ? WHERE sale_id = ?", [$ref, $saleId]);
        return $ref;
    }

    // ---------------------------------------------------------- counter sale (Flow 3.2)

    public static function sellableItems(): array
    {
        return q_all("SELECT s.item_id, s.item_code, s.name, s.item_type, s.unit_of_measure, s.qty_available, i.selling_price, i.allows_decimal
                      FROM v_item_stock s JOIN items i ON i.item_id = s.item_id
                      WHERE i.is_sellable = 1 AND i.is_archived = 0 AND s.qty_available > 0 ORDER BY s.name");
    }

    public static function counterSale(int $uid, array $d, array $lineRows, array $payRows): array
    {
        $lineRows = array_values(array_filter($lineRows, fn($l) => ($l['item_id'] ?? '') !== '' && to_num($l['qty'] ?? 0) > 0));
        if (!$lineRows) throw new UserError('Add at least one item.');

        return tx(function () use ($uid, $d, $lineRows, $payRows) {
            $s = self::requireSession($uid);
            lock_items(array_column($lineRows, 'item_id'));

            $lines = [];
            foreach ($lineRows as $l) {
                $item = q_one("SELECT * FROM items WHERE item_id = ?", [(int)$l['item_id']]);
                if (!$item || !$item['is_sellable'] || $item['is_archived']) throw new UserError('One of the items is not for sale.');
                if ((float)$item['selling_price'] <= 0) throw new UserError('The selling price for ' . $item['name'] . ' has not been configured by an Executive Officer.');
                $n = to_num($l['qty']);
                require_whole($n, $item);
                $already = array_sum(array_map(fn($x) => $x['item_id'] == $item['item_id'] ? $x['qty'] : 0, $lines));
                $avail = stock_available((int)$item['item_id']);
                if ($n + $already > $avail) throw new UserError("Only " . qty($avail) . " {$item['unit_of_measure']} of {$item['name']} in stock.");
                $lines[] = ['item_id' => (int)$item['item_id'], 'qty' => $n, 'price' => (float)$item['selling_price'],
                            'total' => round($n * (float)$item['selling_price'], 2)];
            }
            $gross = round(array_sum(array_column($lines, 'total')), 2);

            // Discounts: a reason always; an Executive Officer's approval above the limit.
            $disc = to_num($d['discount_amount'] ?? 0);
            $reason = trim($d['discount_reason'] ?? '');
            $approver = null;
            if ($disc > 0) {
                if (!reason_ok($reason)) throw new UserError(reason_msg('Give a reason for the discount.'));
                if ($disc > $gross) throw new UserError('The discount cannot be more than the sale.');
                $limit = (float) setting('discount_limit_pct', 5);
                if ($disc > round($gross * $limit / 100, 2)) {
                    if (trim($d['approver_employee_id'] ?? '') === '') {
                        throw new UserError("Discounts above $limit% (" . money($gross * $limit / 100) . ") need an Executive Officer to approve on this screen.");
                    }
                    $approver = (int) verify_admin(trim($d['approver_employee_id']), (string)($d['approver_password'] ?? ''))['user_id'];
                }
            }
            $taxable = round($gross - $disc, 2);
            $vatRate = (float) setting('vat_rate', 12);
            $vat = round($taxable * $vatRate / 100, 2);
            $due = round($taxable + $vat, 2);
            [$pays, $change] = self::parsePayments($payRows, $due);

            $or = self::useReceipt($d['or_number'] ?? '', 'Sale');
            [$customerId, $customerName, $customerContact] = CustomerService::resolve($d, true);
            if ($customerId && trim($d['customer_contact'] ?? '') !== '') $customerContact = trim($d['customer_contact']);
            q("INSERT INTO sales (sale_ref_no, or_number, sale_type, customer_id, customer_name, customer_contact, gross_amount, taxable_amount, vat_rate, vat_amount, discount_amount,
                                  discount_reason, discount_approved_by, amount_due, change_given, session_id, recorded_by)
               VALUES (?, ?, 'Counter Sale', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
                'TMP-' . bin2hex(random_bytes(6)), $or, $customerId, $customerName,
                $customerContact, $gross, $taxable, $vatRate, $vat, $disc, $reason ?: null, $approver, $due, $change, $s['session_id'], $uid,
            ]);
            $saleId = (int) db()->lastInsertId();
            $ref = self::nextRef($saleId);
            foreach ($lines as $l) {
                q("INSERT INTO sale_lines (sale_id, line_type, item_id, qty, unit_price, line_total) VALUES (?, 'Item', ?,?,?,?)",
                  [$saleId, $l['item_id'], $l['qty'], $l['price'], $l['total']]);
                add_movement($l['item_id'], -$l['qty'], 'Sale', 'sale_lines', (int) db()->lastInsertId(), $uid);
            }
            self::insertPayments($pays, $saleId, null);
            audit('SALE', 'sales', $saleId, null, ['ref' => $ref, 'or' => $or, 'due' => $due, 'discount' => $disc]);
            return ['sale_id' => $saleId, 'ref' => $ref, 'change' => $change, 'received' => round(array_sum(array_column($pays, 'amount')), 2)];
        });
    }

    // ---------------------------------------------------------- downpayments (Flow 3.3)

    public static function downpaymentJobs(): array
    {
        return q_all("SELECT j.job_id, j.job_ref_no, j.client_name, j.estimated_total,
                        COALESCE((SELECT SUM(amount) FROM downpayments d WHERE d.job_id = j.job_id AND d.status = 'Recorded'), 0) AS held
                      FROM jobs j WHERE j.status = 'Open' AND j.billing_status = 'Unbilled' AND j.estimated_total IS NOT NULL
                      ORDER BY j.job_id DESC");
    }

    public static function recordDownpayment(int $uid, int $jobId, array $d, array $payRows): int
    {
        return tx(function () use ($uid, $jobId, $d, $payRows) {
            $s = self::requireSession($uid);
            $j = q_one("SELECT * FROM jobs WHERE job_id = ? FOR UPDATE", [$jobId]);
            if (!$j || $j['status'] !== 'Open' || $j['billing_status'] !== 'Unbilled') throw new UserError('Downpayments can only be taken for Open, unbilled jobs.');
            if ($j['estimated_total'] === null) throw new UserError('Ask an Executive Officer to set the estimated total for this job first.');
            $amt = to_num($d['amount'] ?? 0);
            if ($amt <= 0) throw new UserError('Enter the downpayment amount.');
            $held = (float) q_val("SELECT COALESCE(SUM(amount),0) FROM downpayments WHERE job_id = ? AND status = 'Recorded'", [$jobId]);
            if ($held + $amt > (float)$j['estimated_total']) {
                throw new UserError('Downpayments cannot exceed the estimated total. At most ' . money((float)$j['estimated_total'] - $held) . ' more can be taken.');
            }
            [$pays, $change] = self::parsePayments($payRows, $amt);
            if ($change > 0) throw new UserError('Enter the exact downpayment amount in the payment line.');
            $or = self::useReceipt($d['or_number'] ?? '', 'Downpayment');
            q("INSERT INTO downpayments (job_id, or_number, amount, session_id, recorded_by) VALUES (?,?,?,?,?)",
              [$jobId, $or, $amt, $s['session_id'], $uid]);
            $id = (int) db()->lastInsertId();
            self::insertPayments($pays, null, $id);
            audit('DOWNPAYMENT', 'downpayments', $id, null, ['job' => $j['job_ref_no'], 'amount' => $amt, 'or' => $or]);
            return $id;
        });
    }

    // ---------------------------------------------------------- job billing (Flow 3.4)

    /** Jobs that can be billed once material usage is known. Other follow-up can continue afterward. */
    public static function billableJobs(): array
    {
        return q_all("SELECT j.job_id, j.job_ref_no, j.client_name, j.service_fee, j.estimated_total
                      FROM jobs j
                      WHERE j.status = 'Open' AND j.billing_status = 'Unbilled'
                        AND NOT EXISTS (SELECT 1 FROM v_job_materials m WHERE m.job_id = j.job_id AND m.lines_not_declared > 0)
                      ORDER BY j.job_id");
    }

    /** What the bill will contain. Prices come from the item list; nothing is typed. */
    public static function billingPreview(int $jobId): array
    {
        $j = q_one("SELECT * FROM jobs WHERE job_id = ?", [$jobId]);
        if (!$j) throw new UserError('That job does not exist.');
        $problems = [];
        $warnings = [];
        if ($j['status'] !== 'Open' || $j['billing_status'] !== 'Unbilled') $problems[] = 'This job is not open for billing.';
        $toolsOut = JobService::toolsOut($jobId);
        if ($toolsOut > 0) $warnings[] = qty($toolsOut) . ' tool(s) are still out. They can be returned after billing.';
        if ((int) q_val("SELECT COALESCE(SUM(lines_not_declared),0) FROM v_job_materials WHERE job_id = ?", [$jobId]) > 0) $problems[] = 'The technician has not declared all leftovers.';
        $awaitingReleases = (int) q_val("SELECT COUNT(*) FROM releases WHERE job_id = ? AND confirm_status = 'Awaiting Confirmation'", [$jobId]);
        $disputedReleases = (int) q_val("SELECT COUNT(*) FROM releases WHERE job_id = ? AND confirm_status = 'Disputed'", [$jobId]);
        if ($awaitingReleases > 0) $warnings[] = $awaitingReleases . ' release(s) still need technician confirmation. Billing can continue, and the confirmation remains on the audit trail.';
        if ($disputedReleases > 0) $problems[] = $disputedReleases . ' release(s) are disputed. Logistics must resolve the discrepancy before this job can be billed.';

        $planned = [];
        foreach (JobService::jobItems($jobId) as $ji) {
            if ($ji['item_type'] !== 'Consumable') continue;
            $left = round((float)$ji['qty'] - (float)$ji['qty_released'], 2);
            $planned[] = $ji + ['qty_left' => $left];
            if ($left > 0) $warnings[] = 'Required item not released yet: ' . $ji['name'] . ' (' . qty($left) . ' ' . $ji['unit_of_measure'] . ' of ' . qty($ji['qty']) . '). Only materials Logistics released and the technician used are billed.';
        }

        $lines = [];
        foreach (q_all("SELECT m.*, i.unit_of_measure FROM v_job_materials m JOIN items i ON i.item_id = m.item_id WHERE m.job_id = ? AND m.qty_used > 0", [$jobId]) as $m) {
            if ((float)$m['selling_price'] <= 0) $problems[] = 'Set a selling price for ' . $m['item_name'] . ' before billing this job.';
            $lines[] = ['type' => 'Job Material', 'item_id' => (int)$m['item_id'], 'label' => $m['item_name'], 'unit' => $m['unit_of_measure'],
                        'detail' => 'Released ' . qty($m['qty_released']) . ($m['qty_returned'] > 0 ? ', ' . qty($m['qty_returned']) . ' returned' : '') . ', used ' . qty($m['qty_used']), 'qty' => (float)$m['qty_used'],
                        'price' => (float)$m['selling_price'], 'total' => round((float)$m['qty_used'] * (float)$m['selling_price'], 2)];
        }
        if ((float)$j['service_fee'] > 0) {
            $lines[] = ['type' => 'Service Fee', 'item_id' => null, 'label' => 'Service fee (' . $j['service_type'] . ')', 'unit' => '', 'detail' => 'Labor, as agreed on the job', 'qty' => 1.0,
                        'price' => (float)$j['service_fee'], 'total' => (float)$j['service_fee']];
        }
        $gross = round(array_sum(array_column($lines, 'total')), 2);
        $dps = q_all("SELECT * FROM downpayments WHERE job_id = ? AND status = 'Recorded'", [$jobId]);
        $dpTotal = round(array_sum(array_column($dps, 'amount')), 2);
        $vatRate = (float) setting('vat_rate', 12);
        $taxable = $gross;
        $vat = round($taxable * $vatRate / 100, 2);
        $totalWithVat = round($taxable + $vat, 2);
        $due = max(0, round($totalWithVat - $dpTotal, 2));
        $dpExcess = max(0, round($dpTotal - $totalWithVat, 2));
        if ($gross <= 0) $problems[] = 'There is nothing to bill. If the job is free, ask an Executive Officer to mark it No Charge.';
        return ['job' => $j, 'planned' => $planned, 'lines' => $lines, 'gross' => $gross, 'taxable' => $taxable, 'vat_rate'=>$vatRate, 'vat_amount'=>$vat, 'total_with_vat'=>$totalWithVat,
                'downpayments' => $dps, 'dp_total' => $dpTotal, 'dp_excess'=>$dpExcess, 'due' => $due, 'problems' => $problems, 'warnings' => $warnings];
    }

    public static function billJob(int $uid, int $jobId, array $d, array $payRows): array
    {
        return tx(function () use ($uid, $jobId, $d, $payRows) {
            $s = self::requireSession($uid);
            q("SELECT job_id FROM jobs WHERE job_id = ? FOR UPDATE", [$jobId]);
            $p = self::billingPreview($jobId);
            if ($p['problems']) throw new UserError($p['problems'][0]);
            [$pays, $change] = self::parsePayments($payRows, $p['due']);
            $or = self::useReceipt($d['or_number'] ?? '', 'Sale');
            $applied = min($p['dp_total'], $p['total_with_vat']);
            q("INSERT INTO sales (sale_ref_no, or_number, sale_type, billing_kind, job_id, customer_id, customer_name, gross_amount, taxable_amount, vat_rate, vat_amount,
                                  downpayments_applied, amount_due, change_given, session_id, recorded_by)
               VALUES (?,?, 'Job Billing', 'Regular', ?,?,?,?,?,?,?,?,?,?,?,?)", [
                'TMP-' . bin2hex(random_bytes(6)), $or, $jobId, $p['job']['customer_id'], $p['job']['client_name'], $p['gross'], $p['taxable'], $p['vat_rate'], $p['vat_amount'], $applied,
                $p['due'], $change, $s['session_id'], $uid,
            ]);
            $saleId = (int) db()->lastInsertId();
            $ref = self::nextRef($saleId);
            foreach ($p['lines'] as $l) {
                // Job materials already left stock at release, so no stock movement here.
                q("INSERT INTO sale_lines (sale_id, line_type, item_id, qty, unit_price, line_total) VALUES (?,?,?,?,?,?)",
                  [$saleId, $l['type'], $l['item_id'], $l['qty'], $l['price'], $l['total']]);
            }
            self::insertPayments($pays, $saleId, null);
            $remainingToApply = $p['total_with_vat'];
            foreach ($p['downpayments'] as $dp) {
                $apply = min((float)$dp['amount'], max(0, $remainingToApply));
                if ($apply > 0) {
                    q("UPDATE downpayments SET status='Applied', applied_sale_id=?, applied_amount=? WHERE downpayment_id=?", [$saleId,$apply,$dp['downpayment_id']]);
                    $remainingToApply = round($remainingToApply - $apply, 2);
                }
            }
            q("UPDATE jobs SET billing_status = 'Billed' WHERE job_id = ?", [$jobId]);
            audit('JOB_BILLING', 'sales', $saleId, null, ['ref' => $ref, 'job' => $p['job']['job_ref_no'], 'gross' => $p['gross'], 'due' => $p['due']]);
            return ['sale_id' => $saleId, 'ref' => $ref, 'change' => $change, 'received' => round(array_sum(array_column($pays, 'amount')), 2)];
        });
    }

    // ---------------------------------------------------------- reports

    public static function sales(string $date, ?int $sessionId = null): array
    {
        $sql = "SELECT s.*, u.full_name AS recorded_name, ds.drawer_no
                FROM sales s JOIN drawer_sessions ds ON ds.session_id = s.session_id
                JOIN sales_days d ON d.sales_day_id = ds.sales_day_id JOIN users u ON u.user_id = s.recorded_by
                WHERE d.business_date = ?";
        $p = [$date];
        if ($sessionId) { $sql .= " AND s.session_id = ?"; $p[] = $sessionId; }
        return q_all($sql . " ORDER BY s.sale_id DESC", $p);
    }

    public static function sale(int $id): array
    {
        $s = q_one("SELECT s.*, u.full_name AS recorded_name, ds.drawer_no, a.full_name AS approver_name, j.job_ref_no, j.service_type, j.job_site
                    FROM sales s JOIN users u ON u.user_id = s.recorded_by JOIN drawer_sessions ds ON ds.session_id = s.session_id
                    LEFT JOIN users a ON a.user_id = s.discount_approved_by LEFT JOIN jobs j ON j.job_id = s.job_id
                    WHERE s.sale_id = ?", [$id]);
        if (!$s) throw new UserError('That sale does not exist.');
        $s['lines'] = q_all("SELECT sl.*, i.name, i.unit_of_measure FROM sale_lines sl LEFT JOIN items i ON i.item_id = sl.item_id WHERE sl.sale_id = ? ORDER BY sl.sale_line_id", [$id]);
        $s['payments'] = q_all("SELECT * FROM payments WHERE sale_id = ? ORDER BY payment_id", [$id]);
        $s['downpayments'] = q_all("SELECT * FROM downpayments WHERE applied_sale_id = ?", [$id]);
        return $s;
    }

    public static function summary(string $date): ?array
    {
        return q_one("SELECT * FROM v_daily_summary WHERE business_date = ?", [$date]);
    }

    public static function daySessions(string $date): array
    {
        return q_all("SELECT v.*, ds.closed_at, ds.difference, ds.difference_explanation, u.full_name
                      FROM v_session_cash v JOIN drawer_sessions ds ON ds.session_id = v.session_id
                      JOIN sales_days d ON d.sales_day_id = ds.sales_day_id JOIN users u ON u.user_id = ds.sales_staff_id
                      WHERE d.business_date = ? ORDER BY ds.opened_at", [$date]);
    }
}
