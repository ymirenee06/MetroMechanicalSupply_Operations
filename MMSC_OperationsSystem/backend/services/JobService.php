<?php
// Objective 2, part 1: the Job Reference List.

class JobService
{
    public static function technicians(): array
    {
        return q_all("SELECT user_id, full_name FROM users WHERE role = 'Technician' AND status = 'Active' ORDER BY full_name");
    }

    public static function jobs(string $filter = 'open', ?int $technicianId = null): array
    {
        $where = [
            'open'     => "j.status = 'Open'",
            'unbilled' => "j.status = 'Open' AND j.billing_status = 'Unbilled'",
            'closed'   => "j.status = 'Closed'",
            'all'      => "1 = 1",
        ][$filter] ?? "j.status = 'Open'";
        $techScope = $technicianId ? " AND EXISTS (SELECT 1 FROM job_technicians jt_scope WHERE jt_scope.job_id=j.job_id AND jt_scope.technician_id=" . (int)$technicianId . " AND jt_scope.removed_at IS NULL)" : '';
        return q_all("SELECT j.*,
                        (SELECT GROUP_CONCAT(u.full_name ORDER BY u.full_name SEPARATOR ', ')
                           FROM job_technicians jt JOIN users u ON u.user_id = jt.technician_id
                          WHERE jt.job_id = j.job_id AND jt.removed_at IS NULL) AS technicians,
                        (SELECT COALESCE(SUM(qty_held), 0) FROM v_tool_holdings h WHERE h.job_id = j.job_id) AS tools_out
                      FROM jobs j WHERE $where $techScope ORDER BY j.job_id DESC LIMIT 200");
    }

    public static function job(int $id): array
    {
        $j = q_one("SELECT j.*, u.full_name AS registered_name FROM jobs j JOIN users u ON u.user_id = j.registered_by WHERE j.job_id = ?", [$id]);
        if (!$j) throw new UserError('That job does not exist.');
        return $j;
    }

    public static function jobTechnicians(int $jobId): array
    {
        return q_all("SELECT jt.*, u.full_name FROM job_technicians jt JOIN users u ON u.user_id = jt.technician_id
                      WHERE jt.job_id = ? AND jt.removed_at IS NULL ORDER BY u.full_name", [$jobId]);
    }

    // ---------------------------------------------------------- job templates

    public static function templates(bool $activeOnly = false): array
    {
        return q_all("SELECT t.*, (SELECT COUNT(*) FROM job_template_items ti WHERE ti.template_id = t.template_id) AS item_count,
                             (SELECT COUNT(*) FROM jobs j WHERE j.template_id = t.template_id) AS times_used
                      FROM job_templates t" . ($activeOnly ? " WHERE t.is_active = 1" : "") . " ORDER BY t.is_active DESC, t.service_type, t.name");
    }

    public static function template(int $id): array
    {
        $t = q_one("SELECT * FROM job_templates WHERE template_id = ?", [$id]);
        if (!$t) throw new UserError('That job template does not exist.');
        $t['items'] = q_all("SELECT ti.item_id, ti.qty, i.item_type, i.name, i.item_code, i.unit_of_measure, i.selling_price, i.is_sellable, i.is_archived
                             FROM job_template_items ti JOIN items i ON i.item_id = ti.item_id WHERE ti.template_id = ? ORDER BY i.item_type DESC, i.name", [$id]);
        return $t;
    }

    /** {template_id: {service_type, fee, items: [{line_type, item_id, qty}]}} for the Register job page. */
    public static function templateMap(): array
    {
        $map = [];
        foreach (self::templates(true) as $t) {
            $rows = [];
            foreach (self::template((int)$t['template_id'])['items'] as $it) {
                if ($it['is_archived'] || ($it['item_type'] === 'Tool/Equipment' && $it['is_sellable'])) continue;
                $rows[] = ['line_type' => $it['item_type'] === 'Tool/Equipment' ? 'Tool' : 'Consumable', 'item_id' => (string)$it['item_id'], 'qty' => qty($it['qty'])];
            }
            $map[$t['template_id']] = ['service_type' => $t['service_type'], 'fee' => (float)$t['default_service_fee'], 'items' => $rows];
        }
        return $map;
    }

    /** Creates or updates a template (Executive Officer). $id = 0 creates a new one. */
    public static function saveTemplate(int $id, array $d, array $rows, int $adminId): int
    {
        if (!has_role('Executive Officer') || uid() !== $adminId) throw new UserError('Only an Executive Officer can manage job templates.');
        $name = trim($d['name'] ?? '');
        if ($name === '') throw new UserError('Give the template a name, e.g. "Split-type aircon installation".');
        $type = in_array($d['service_type'] ?? '', ['Installation','Repair','Maintenance','Other'], true) ? $d['service_type'] : 'Other';
        $fee = to_num($d['default_service_fee'] ?? 0);
        if ($fee < 0) throw new UserError('The service fee cannot be negative.');
        $priced = self::priceRequiredItems($rows);   // same checks as a real job: valid items, whole numbers, company tools only
        if (!$priced) throw new UserError('Add at least one item to the template.');
        return tx(function () use ($id, $name, $type, $fee, $d, $priced, $adminId) {
            if (q_val("SELECT 1 FROM job_templates WHERE name = ? AND template_id <> ?", [$name, $id])) throw new UserError('Another template already has that name.');
            if ($id) {
                if (!q_val("SELECT 1 FROM job_templates WHERE template_id = ?", [$id])) throw new UserError('That job template does not exist.');
                q("UPDATE job_templates SET name = ?, service_type = ?, default_service_fee = ?, notes = ?, updated_at = NOW() WHERE template_id = ?",
                  [$name, $type, $fee, trim($d['notes'] ?? '') ?: null, $id]);
                q("DELETE FROM job_template_items WHERE template_id = ?", [$id]);
            } else {
                q("INSERT INTO job_templates (name, service_type, default_service_fee, notes, created_by) VALUES (?,?,?,?,?)", [$name, $type, $fee, trim($d['notes'] ?? '') ?: null, $adminId]);
                $id = (int) db()->lastInsertId();
            }
            foreach ($priced as $p) q("INSERT INTO job_template_items (template_id, item_id, qty) VALUES (?,?,?)", [$id, $p['item_id'], $p['qty']]);
            audit('JOB_TEMPLATE_SAVE', 'job_templates', $id, null, ['name' => $name, 'items' => count($priced)]);
            return $id;
        });
    }

    public static function setTemplateActive(int $id, bool $active, int $adminId): void
    {
        if (!has_role('Executive Officer') || uid() !== $adminId) throw new UserError('Only an Executive Officer can manage job templates.');
        q("UPDATE job_templates SET is_active = ?, updated_at = NOW() WHERE template_id = ?", [$active ? 1 : 0, $id]);
        audit($active ? 'JOB_TEMPLATE_RESTORE' : 'JOB_TEMPLATE_HIDE', 'job_templates', $id);
    }

    /** Next suggested job order number, e.g. JO-0042. */
    public static function nextRef(): string
    {
        $n = (int) q_val("SELECT COALESCE(MAX(CAST(SUBSTRING(job_ref_no, 4) AS UNSIGNED)), 0) + 1 FROM jobs WHERE job_ref_no REGEXP '^JO-[0-9]+$'");
        return sprintf('JO-%04d', $n);
    }

    /**
     * Cleans the "required items" rows from the form and prices them from the item list.
     * Tools are listed so Logistics knows what to prepare, but only consumables are billed.
     */
    public static function priceRequiredItems(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $itemId = (int)($r['item_id'] ?? 0);
            $qn = to_num($r['qty'] ?? 0);
            if (!$itemId || $qn <= 0) continue;
            $item = q_one("SELECT * FROM items WHERE item_id = ? AND is_archived = 0", [$itemId]);
            if (!$item) throw new UserError('One of the required items is no longer available.');
            if ($item['item_type'] === 'Tool/Equipment' && $item['is_sellable']) throw new UserError($item['name'] . ' is a tool for sale. List company tools only; a tool the client buys is a counter sale.');
            require_whole($qn, $item);
            if (isset($out[$itemId])) { $out[$itemId]['qty'] += $qn; continue; }
            $out[$itemId] = ['item_id' => $itemId, 'qty' => $qn, 'item' => $item,
                             'unit_price' => $item['item_type'] === 'Consumable' ? (float)$item['selling_price'] : 0.0];
        }
        return array_values($out);
    }

    /** Service fee + consumables, then VAT. This is the amount the client is quoted. */
    public static function estimate(float $serviceFee, array $pricedItems): array
    {
        $materials = 0.0;
        foreach ($pricedItems as $p) $materials += round($p['qty'] * $p['unit_price'], 2);
        $sub = round($serviceFee + $materials, 2);
        $vatRate = (float) setting('vat_rate', 12);
        $vat = round($sub * $vatRate / 100, 2);
        return ['service_fee' => $serviceFee, 'materials' => round($materials, 2), 'subtotal' => $sub, 'vat_rate' => $vatRate, 'vat' => $vat, 'total' => round($sub + $vat, 2)];
    }

    /** Flow 2.1 */
    public static function create(array $d, array $techIds, int $adminId): int
    {
        return tx(function () use ($d, $techIds, $adminId) {
            if (trim($d['job_ref_no'] ?? '') === '') throw new UserError('Enter the job order number.');
            if (q_val("SELECT 1 FROM jobs WHERE job_ref_no = ?", [trim($d['job_ref_no'])])) throw new UserError('That job order number is already used.');
            [$customerId, $clientName] = CustomerService::resolve($d, false);
            if (!$techIds) throw new UserError('Assign at least one technician.');
            $fee = to_num($d['service_fee'] ?? 0);
            if ($fee < 0) throw new UserError('The service fee cannot be negative.');
            $priced = self::priceRequiredItems(post_rows('items'));
            $est = self::estimate($fee, $priced);
            $templateId = ctype_digit((string)($d['template_id'] ?? '')) && q_val("SELECT 1 FROM job_templates WHERE template_id = ?", [(int)$d['template_id']]) ? (int)$d['template_id'] : null;
            q("INSERT INTO jobs (job_ref_no, client_name, customer_id, job_site, service_type, template_id, job_date, service_fee, estimated_total, registered_by)
               VALUES (?,?,?,?,?,?,?,?,?,?)", [
                trim($d['job_ref_no']), $clientName, $customerId, trim($d['job_site'] ?? '') ?: null,
                in_array($d['service_type'] ?? '', ['Installation','Repair','Maintenance','Other'], true) ? $d['service_type'] : 'Other',
                $templateId, ($d['job_date'] ?? '') ?: today(), $fee, $est['total'], $adminId,
            ]);
            $id = (int) db()->lastInsertId();
            foreach (array_unique(array_map('intval', $techIds)) as $t) {
                q("INSERT INTO job_technicians (job_id, technician_id, added_by) VALUES (?,?,?)", [$id, $t, $adminId]);
            }
            foreach ($priced as $p) {
                q("INSERT INTO job_items (job_id, item_id, qty, unit_price, added_by) VALUES (?,?,?,?,?)", [$id, $p['item_id'], $p['qty'], $p['unit_price'], $adminId]);
            }
            audit('JOB_CREATE', 'jobs', $id, null, ['job_ref_no' => trim($d['job_ref_no']), 'technicians' => $techIds, 'required_items' => count($priced), 'estimated_total' => $est['total']]);
            return $id;
        });
    }

    /** Required items with how much has already been released for the job. */
    public static function jobItems(int $jobId): array
    {
        return q_all("SELECT ji.*, i.item_code, i.name, i.item_type, i.unit_of_measure, i.allows_decimal, s.qty_available,
                        COALESCE((SELECT SUM(ri.qty) FROM release_items ri JOIN releases r ON r.release_id = ri.release_id
                                   WHERE r.job_id = ji.job_id AND ri.item_id = ji.item_id), 0) AS qty_released
                      FROM job_items ji JOIN items i ON i.item_id = ji.item_id JOIN v_item_stock s ON s.item_id = ji.item_id
                      WHERE ji.job_id = ? ORDER BY i.item_type DESC, i.name", [$jobId]);
    }

    /** Replaces the required-items list (Executive Officer, before billing) and recomputes the estimate. */
    public static function saveJobItems(int $jobId, array $rows, int $adminId): void
    {
        tx(function () use ($jobId, $rows, $adminId) {
            $j = q_one("SELECT * FROM jobs WHERE job_id = ? FOR UPDATE", [$jobId]);
            if (!$j) throw new UserError('That job does not exist.');
            if ($j['billing_status'] !== 'Unbilled' || $j['status'] !== 'Open') throw new UserError('Required items can only change on an open, unbilled job.');
            $priced = self::priceRequiredItems($rows);
            q("DELETE FROM job_items WHERE job_id = ?", [$jobId]);
            foreach ($priced as $p) {
                q("INSERT INTO job_items (job_id, item_id, qty, unit_price, added_by) VALUES (?,?,?,?,?)", [$jobId, $p['item_id'], $p['qty'], $p['unit_price'], $adminId]);
            }
            self::recomputeEstimate($jobId, 'Required items updated', $adminId);
            audit('JOB_ITEMS_UPDATE', 'jobs', $jobId, null, ['required_items' => count($priced)]);
        });
    }

    /** Keeps the estimate equal to service fee + required consumables + VAT. Logged like any fee change. */
    public static function recomputeEstimate(int $jobId, string $reason, int $adminId): void
    {
        $j = q_one("SELECT * FROM jobs WHERE job_id = ?", [$jobId]);
        $priced = array_map(fn($r) => ['qty' => (float)$r['qty'], 'unit_price' => (float)$r['unit_price']],
                            q_all("SELECT qty, unit_price FROM job_items WHERE job_id = ?", [$jobId]));
        $est = self::estimate((float)$j['service_fee'], $priced)['total'];
        if ($j['estimated_total'] !== null && abs((float)$j['estimated_total'] - $est) < 0.005) return;
        $held = (float) q_val("SELECT COALESCE(SUM(amount),0) FROM downpayments WHERE job_id = ? AND status = 'Recorded'", [$jobId]);
        if ($est < $held) throw new UserError('The new estimate (' . money($est) . ') would be lower than the ' . money($held) . ' already received as downpayment.');
        q("INSERT INTO job_fee_changes (job_id, field, old_value, new_value, reason, changed_by) VALUES (?,?,?,?,?,?)",
          [$jobId, 'estimated_total', $j['estimated_total'] ?? 0, $est, $reason, $adminId]);
        q("UPDATE jobs SET estimated_total = ? WHERE job_id = ?", [$est, $jobId]);
    }

    public static function addTechnician(int $jobId, int $techId, int $adminId): void
    {
        $j = self::job($jobId);
        if ($j['status'] !== 'Open') throw new UserError('Technicians can only be added to Open jobs.');
        $t = q_one("SELECT user_id, role, status FROM users WHERE user_id = ?", [$techId]);
        if (!$t || $t['role'] !== 'Technician' || $t['status'] !== 'Active') throw new UserError('Choose an active Technician account.');
        if (q_val("SELECT 1 FROM job_technicians WHERE job_id = ? AND technician_id = ? AND removed_at IS NULL", [$jobId, $techId])) {
            throw new UserError('That technician is already assigned to this job.');
        }
        q("INSERT INTO job_technicians (job_id, technician_id, added_by) VALUES (?,?,?)", [$jobId, $techId, $adminId]);
        audit('JOB_TECH_ADD', 'jobs', $jobId, null, ['technician_id' => $techId]);
    }

    /** Fee changes are kept with a reason, before billing only. */
    public static function changeFee(int $jobId, string $field, float $value, string $reason, int $adminId): void
    {
        if (!in_array($field, ['service_fee', 'estimated_total'], true)) throw new UserError('Choose which amount to change.');
        if (!reason_ok($reason)) throw new UserError(reason_msg('Give a reason for the change.'));
        tx(function () use ($jobId, $field, $value, $reason, $adminId) {
            $j = q_one("SELECT * FROM jobs WHERE job_id = ? FOR UPDATE", [$jobId]);
            if ($j['billing_status'] !== 'Unbilled') throw new UserError('The fee can only change before the job is billed.');
            if ($field === 'estimated_total') {
                $held = (float) q_val("SELECT COALESCE(SUM(amount),0) FROM downpayments WHERE job_id = ? AND status = 'Recorded'", [$jobId]);
                if ($value < $held) throw new UserError('The estimate cannot be lower than the ' . money($held) . ' in downpayments already received.');
            }
            q("INSERT INTO job_fee_changes (job_id, field, old_value, new_value, reason, changed_by) VALUES (?,?,?,?,?,?)",
              [$jobId, $field, $j[$field], $value, $reason, $adminId]);
            q("UPDATE jobs SET $field = ? WHERE job_id = ?", [$value, $jobId]);   // $field is whitelisted above
            audit('JOB_FEE_CHANGE', 'jobs', $jobId, [$field => $j[$field]], [$field => $value, 'reason' => $reason]);
            if ($field === 'service_fee') self::recomputeEstimate($jobId, 'Recomputed after service fee change: ' . $reason, $adminId);
        });
    }

    public static function toolsOut(int $jobId): float
    {
        return (float) q_val("SELECT COALESCE(SUM(qty_held),0) FROM v_tool_holdings WHERE job_id = ?", [$jobId]);
    }

    /** Flow 2.9: record the final job state. Operational follow-up may continue afterward. */
    public static function close(int $jobId, int $userId): void
    {
        $j = self::job($jobId);
        if ($j['status'] !== 'Open') throw new UserError('This job is already closed.');
        if ($j['billing_status'] === 'Unbilled') throw new UserError('Bill the job or mark it No Charge before closing it.');
        $toolsOut = self::toolsOut($jobId);
        if ($toolsOut > 0) throw new UserError('Return all tools before closing this job. Cancellation does not have this requirement.');
        $undeclared = (float) q_val("SELECT COALESCE(SUM(lines_not_declared),0) FROM v_job_materials WHERE job_id = ?", [$jobId]);
        if ($undeclared > 0) throw new UserError('All released consumables still need a technician declaration before this job can close.');
        $unreceivedLeftovers = (int) q_val("SELECT COUNT(*) FROM consumable_leftovers cl JOIN release_items ri ON ri.release_item_id = cl.release_item_id JOIN releases r ON r.release_id = ri.release_id WHERE r.job_id = ? AND cl.qty_returned > 0 AND cl.received_by IS NULL", [$jobId]);
        if ($unreceivedLeftovers > 0) throw new UserError('Logistics still has leftover consumables to receive before this job can close.');
        $disputed = (int) q_val("SELECT COUNT(*) FROM releases WHERE job_id = ? AND confirm_status = 'Disputed'", [$jobId]);
        if ($disputed > 0) throw new UserError('Resolve the disputed release before closing this job.');
        q("UPDATE jobs SET status = 'Closed', closed_by = ?, closed_at = NOW() WHERE job_id = ?", [$userId, $jobId]);
        audit('JOB_CLOSE', 'jobs', $jobId, null, ['tools_still_out' => $toolsOut, 'leftovers_pending' => $undeclared, 'leftovers_unreceived' => $unreceivedLeftovers, 'disputes' => $disputed]);
    }

    public static function reopen(int $jobId, string $reason, int $adminId): void
    {
        if (!reason_ok($reason)) throw new UserError(reason_msg('Give a reason for reopening the job.'));
        tx(function() use($jobId,$reason,$adminId){
            $j=q_one("SELECT * FROM jobs WHERE job_id=? FOR UPDATE",[$jobId]);
            if(!$j) throw new UserError('That job does not exist.');
            if($j['status']!=='Closed') throw new UserError('Only a closed job can be reopened.');
            if($j['billing_status']==='Cancelled') throw new UserError('Cancelled jobs cannot be reopened from the normal reopen flow.');
            q("UPDATE jobs SET status='Open', closed_by=NULL, closed_at=NULL WHERE job_id=?",[$jobId]);
            audit('JOB_REOPEN','jobs',$jobId,null,['reason'=>trim($reason),'previous_billing_status'=>$j['billing_status']]);
        });
    }

    public static function markNoCharge(int $jobId, string $reason, int $adminId): void
    {
        if (!reason_ok($reason)) throw new UserError(reason_msg('Give a reason for No Charge, such as a warranty callback.'));
        $j = self::job($jobId);
        if ($j['billing_status'] !== 'Unbilled') throw new UserError('Only unbilled jobs can be marked No Charge.');
        if ((float) q_val("SELECT COALESCE(SUM(amount),0) FROM downpayments WHERE job_id = ? AND status = 'Recorded'", [$jobId]) > 0) {
            throw new UserError('This job has downpayments. Refund them first.');
        }
        q("UPDATE jobs SET billing_status = 'No Charge', no_charge_reason = ? WHERE job_id = ?", [$reason, $jobId]);
        audit('JOB_NO_CHARGE', 'jobs', $jobId, null, ['reason' => $reason]);
    }

    /** Final Flow 6.11: cancellation requires the job to be operationally cleaned up first. */
    public static function cancel(int $jobId, string $reason, int $userId): void
    {
        if (!reason_ok($reason)) throw new UserError(reason_msg('Give a reason for the cancellation.'));
        tx(function () use ($jobId, $reason, $userId) {
            $j = q_one("SELECT * FROM jobs WHERE job_id = ? FOR UPDATE", [$jobId]);
            if (!$j) throw new UserError('That job does not exist.');
            if ($j['status'] !== 'Open') throw new UserError('Only open jobs can be cancelled.');
            if ($j['billing_status'] === 'No Charge' || $j['billing_status'] === 'Cancelled') throw new UserError('This job already has a final billing outcome.');

            $toolsOut = self::toolsOut($jobId);
            if ($toolsOut > 0) throw new UserError('Return and inspect all tools before cancelling this job.');

            $undeclared = (float) q_val("SELECT COALESCE(SUM(lines_not_declared),0) FROM v_job_materials WHERE job_id = ?", [$jobId]);
            if ($undeclared > 0) throw new UserError('Declare all consumable leftovers before cancelling this job.');

            $unreceived = (int) q_val("SELECT COUNT(*) FROM consumable_leftovers cl JOIN release_items ri ON ri.release_item_id = cl.release_item_id JOIN releases r ON r.release_id = ri.release_id WHERE r.job_id = ? AND cl.qty_returned > 0 AND cl.received_by IS NULL", [$jobId]);
            if ($unreceived > 0) throw new UserError('Logistics must receive the declared leftover consumables before cancellation.');

            $disputed = (int) q_val("SELECT COUNT(*) FROM releases WHERE job_id = ? AND confirm_status = 'Disputed'", [$jobId]);
            if ($disputed > 0) throw new UserError('Resolve disputed releases before cancelling this job.');
            $awaiting = (int) q_val("SELECT COUNT(*) FROM releases WHERE job_id = ? AND confirm_status = 'Awaiting Confirmation'", [$jobId]);
            if ($awaiting > 0) throw new UserError('Have the technician confirm all releases before cancelling this job.');

            $used = (float) q_val("SELECT COALESCE(SUM(qty_used),0) FROM v_job_materials WHERE job_id = ?", [$jobId]);
            if ($used > 0 && $j['billing_status'] === 'Unbilled') {
                throw new UserError('This job used consumables. Bill the actual used materials before cancellation.');
            }

            $recordedDownpayments = (float) q_val("SELECT COALESCE(SUM(amount),0) FROM downpayments WHERE job_id = ? AND status = 'Recorded'", [$jobId]);
            if ($recordedDownpayments > 0) {
                throw new UserError('This job still has recorded downpayments. Request and process their cancellation refunds before cancelling the job.');
            }

            q("UPDATE jobs
               SET billing_status = 'Cancelled', cancel_reason = ?, status = 'Closed', closed_by = ?, closed_at = NOW()
               WHERE job_id = ?",
              [$reason, $userId, $jobId]);

            audit('JOB_CANCEL', 'jobs', $jobId, null, [
                'reason' => $reason,
                'tools_still_out' => 0,
                'downpayments_recorded' => 0,
                'release_awaiting_confirmation' => 0,
                'release_disputed' => 0,
                'consumables_used' => $used,
            ]);
        });
    }

    /** Everything the job page shows. */
    public static function detail(int $jobId): array
    {
        return [
            'job'         => self::job($jobId),
            'techs'       => self::jobTechnicians($jobId),
            'releases'    => q_all("SELECT r.*, u.full_name AS technician, l.full_name AS released_name,
                                        (SELECT GROUP_CONCAT(CONCAT(i.name, ' × ', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM ri.qty))) SEPARATOR ', ')
                                           FROM release_items ri JOIN items i ON i.item_id = ri.item_id WHERE ri.release_id = r.release_id) AS items
                                    FROM releases r JOIN users u ON u.user_id = r.technician_id JOIN users l ON l.user_id = r.released_by
                                    WHERE r.job_id = ? ORDER BY r.release_id DESC", [$jobId]),
            'holdings'    => q_all("SELECT * FROM v_tool_holdings WHERE job_id = ?", [$jobId]),
            'materials'   => q_all("SELECT * FROM v_job_materials WHERE job_id = ?", [$jobId]),
            'downpayments'=> q_all("SELECT * FROM downpayments WHERE job_id = ? ORDER BY downpayment_id", [$jobId]),
            'fee_changes' => q_all("SELECT f.*, u.full_name FROM job_fee_changes f JOIN users u ON u.user_id = f.changed_by WHERE f.job_id = ? ORDER BY f.fee_change_id DESC", [$jobId]),
            'billing'     => q_one("SELECT * FROM sales WHERE job_id = ? AND status <> 'Voided'", [$jobId]),
            'job_items'   => self::jobItems($jobId),
            'requests'    => q_all("SELECT r.*, i.name AS item_name, i.unit_of_measure, u.full_name AS technician, d.full_name AS decided_name
                                    FROM item_requests r JOIN items i ON i.item_id = r.item_id JOIN users u ON u.user_id = r.technician_id
                                    LEFT JOIN users d ON d.user_id = r.decided_by WHERE r.job_id = ? ORDER BY r.request_id DESC", [$jobId]),
            'pending_returns' => q_all("SELECT tr.*, i.name AS item_name, u.full_name AS technician FROM tool_returns tr
                                    JOIN tool_assignments ta ON ta.assignment_id = tr.assignment_id JOIN items i ON i.item_id = ta.item_id
                                    JOIN users u ON u.user_id = ta.technician_id WHERE ta.job_id = ? AND tr.status = 'Pending Inspection'", [$jobId]),
        ];
    }
}
