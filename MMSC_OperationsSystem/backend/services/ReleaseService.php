<?php
// Employee accountability workflows: releases, confirmations, requests, transfers, extensions, returns, incidents.

class ReleaseService
{
    public static function releasableJobs(): array
    {
        return q_all("SELECT job_id, job_ref_no, client_name FROM jobs WHERE status = 'Open' AND billing_status = 'Unbilled' ORDER BY job_id DESC");
    }

    public static function jobTechMap(): array
    {
        $map = [];
        foreach (q_all("SELECT jt.job_id, u.user_id, u.full_name FROM job_technicians jt JOIN users u ON u.user_id = jt.technician_id
                        JOIN jobs j ON j.job_id = jt.job_id
                        WHERE jt.removed_at IS NULL AND j.status = 'Open' AND u.status = 'Active' ORDER BY u.full_name") as $r) {
            $map[$r['job_id']][] = ['id' => (int)$r['user_id'], 'name' => $r['full_name']];
        }
        return $map;
    }

    public static function technicians(): array
    {
        return q_all("SELECT user_id, employee_id, full_name FROM users WHERE role = 'Technician' AND status = 'Active' ORDER BY full_name");
    }

    public static function releasableItems(): array
    {
        return q_all("SELECT s.item_id, s.item_code, s.name, s.item_type, s.unit_of_measure, s.qty_available, i.allows_decimal, i.selling_price, i.is_sellable
                      FROM v_item_stock s JOIN items i ON i.item_id = s.item_id
                      WHERE s.is_archived = 0 ORDER BY s.name");
    }

    /** For each open job: required items not yet fully released (pre-fills the release form). */
    public static function remainingRequiredMap(): array
    {
        $map = [];
        foreach (q_all("SELECT ji.job_id, ji.item_id, i.item_type, ji.qty,
                          COALESCE((SELECT SUM(ri.qty) FROM release_items ri JOIN releases r ON r.release_id = ri.release_id
                                     WHERE r.job_id = ji.job_id AND ri.item_id = ji.item_id), 0) AS released
                        FROM job_items ji JOIN jobs j ON j.job_id = ji.job_id JOIN items i ON i.item_id = ji.item_id
                        WHERE j.status = 'Open' AND j.billing_status = 'Unbilled' ORDER BY i.item_type DESC, i.name") as $r) {
            $left = round((float)$r['qty'] - (float)$r['released'], 2);
            if ($left > 0) $map[$r['job_id']][] = ['item_id' => (int)$r['item_id'], 'type' => $r['item_type'], 'remaining' => $left];
        }
        return $map;
    }

    /** Approved additional-item requests that Logistics has not released yet. */
    public static function approvedRequestMap(): array
    {
        $map = [];
        foreach (q_all("SELECT r.request_id, r.job_id, r.technician_id, r.item_id, r.qty, i.item_type FROM item_requests r JOIN items i ON i.item_id = r.item_id WHERE r.status = 'Approved'") as $r) {
            $map[$r['request_id']] = ['job_id' => (int)$r['job_id'], 'technician_id' => (int)$r['technician_id'], 'item_id' => (int)$r['item_id'], 'qty' => (float)$r['qty'], 'type' => $r['item_type']];
        }
        return $map;
    }

    /** Final Flow 2.2/2.3. Tool assignment changes custody, not physical quantity. Consumable release reduces stock. */
    /**
     * Release form with a Tool/Consumable choice on EACH item row.
     * Rows are grouped by type and saved as one Tool release and/or one Consumable release,
     * all inside a single transaction (either everything is saved or nothing is).
     * Returns the IDs of the releases created.
     */
    public static function createMixed(int $jobId, int $techId, array $lines, ?int $requestId, int $logisticsId): array
    {
        $lines = array_values(array_filter($lines, fn($l) => ($l['item_id'] ?? '') !== '' && to_num($l['qty'] ?? 0) > 0));
        if (!$lines) throw new UserError('Add at least one item with a quantity.');

        $groups = ['Tool' => [], 'Consumable' => []];
        foreach ($lines as $l) {
            // Group by what the item really is, so a mismatched Type dropdown cannot mislabel it.
            $itemType = q_val("SELECT item_type FROM items WHERE item_id = ?", [(int)$l['item_id']]);
            if (!$itemType) throw new UserError('One of the items no longer exists.');
            $groups[$itemType === 'Tool/Equipment' ? 'Tool' : 'Consumable'][] = $l;
        }

        return tx(function () use ($jobId, $techId, $groups, $requestId, $logisticsId) {
            // The approved additional-item request is linked to the group that holds its item.
            $requestType = null;
            if ($requestId) {
                $reqItemType = q_val("SELECT i.item_type FROM item_requests r JOIN items i ON i.item_id = r.item_id WHERE r.request_id = ?", [$requestId]);
                $requestType = $reqItemType === 'Tool/Equipment' ? 'Tool' : 'Consumable';
                if (!$groups[$requestType]) {
                    throw new UserError('The release must contain the approved request item and exact requested quantity.');
                }
            }
            $ids = [];
            foreach ($groups as $type => $rows) {
                if (!$rows) continue;
                $ids[] = self::insertRelease($jobId, $techId, $type, $rows, $type === $requestType ? $requestId : null, $logisticsId);
            }
            return $ids;
        });
    }

    public static function create(int $jobId, int $techId, string $type, array $lines, ?int $requestId, int $logisticsId): int
    {
        $type = $type === 'Tool' ? 'Tool' : 'Consumable';
        $lines = array_values(array_filter($lines, fn($l) => ($l['item_id'] ?? '') !== '' && to_num($l['qty'] ?? 0) > 0));
        if (!$lines) throw new UserError('Add at least one item with a quantity.');
        return tx(fn() => self::insertRelease($jobId, $techId, $type, $lines, $requestId, $logisticsId));
    }

    /** Saves one release of a single type. Must be called inside tx(). */
    private static function insertRelease(int $jobId, int $techId, string $type, array $lines, ?int $requestId, int $logisticsId): int
    {
        return (function () use ($jobId, $techId, $type, $lines, $requestId, $logisticsId) {
            $job = q_one("SELECT * FROM jobs WHERE job_id = ? FOR UPDATE", [$jobId]);
            if (!$job || $job['status'] !== 'Open' || $job['billing_status'] !== 'Unbilled') {
                throw new UserError('Items can only be released for an Open, unbilled job.');
            }
            if (!q_val("SELECT 1 FROM job_technicians WHERE job_id = ? AND technician_id = ? AND removed_at IS NULL", [$jobId, $techId])) {
                throw new UserError('That technician is not assigned to this job.');
            }
            if ($requestId) {
                $req = q_one("SELECT * FROM item_requests WHERE request_id = ? FOR UPDATE", [$requestId]);
                if (!$req || $req['status'] !== 'Approved' || (int)$req['job_id'] !== $jobId || (int)$req['technician_id'] !== $techId) {
                    throw new UserError('That additional-item request is not approved for this job and technician.');
                }
                if (q_val("SELECT 1 FROM releases WHERE request_id = ?", [$requestId])) {
                    throw new UserError('That approved request has already been linked to a release.');
                }
                $matchesRequest = false;
                foreach ($lines as $requestLine) {
                    if ((int)($requestLine['item_id'] ?? 0) === (int)$req['item_id'] && abs(to_num($requestLine['qty'] ?? 0) - (float)$req['qty']) < 0.00001) {
                        $matchesRequest = true;
                        break;
                    }
                }
                if (!$matchesRequest) {
                    throw new UserError('The release must contain the approved request item and exact requested quantity.');
                }
            }
            lock_items(array_column($lines, 'item_id'));
            q("INSERT INTO releases (job_id, technician_id, release_type, request_id, released_by) VALUES (?,?,?,?,?)",
              [$jobId, $techId, $type, $requestId, $logisticsId]);
            $releaseId = (int) db()->lastInsertId();

            foreach ($lines as $l) {
                $item = q_one("SELECT * FROM items WHERE item_id = ?", [(int)$l['item_id']]);
                $qn = to_num($l['qty']);
                $want = $type === 'Tool' ? 'Tool/Equipment' : 'Consumable';
                if ($item && $item['item_type'] === 'Tool/Equipment' && $item['is_sellable']) throw new UserError($item['name'] . ' is a tool for sale (merchandise). Only company tools can be lent to technicians.');
                if (!$item || $item['item_type'] !== $want) throw new UserError(($item['name'] ?? 'That item') . ' is not a ' . strtolower($want) . ' item. Change its type on that row.');
                require_whole($qn, $item);
                $avail = stock_available((int)$item['item_id']);
                if ($qn > $avail) throw new UserError("Only " . qty($avail) . " {$item['unit_of_measure']} of {$item['name']} available.");

                $cond = $type === 'Tool' ? ($l['condition_out'] ?? 'Good') : null;
                q("INSERT INTO release_items (release_id, item_id, qty, condition_out, condition_notes) VALUES (?,?,?,?,?)",
                  [$releaseId, $item['item_id'], $qn, $cond, trim($l['condition_notes'] ?? '') ?: null]);
                $riId = (int) db()->lastInsertId();

                if ($type === 'Tool') {
                    $due = trim($l['expected_return_date'] ?? '');
                    if ($due === '' || $due < today()) throw new UserError("Set a return date for {$item['name']} (today or later).");
                    q("INSERT INTO tool_assignments (item_id, technician_id, job_id, qty_out, expected_return_date, source, release_item_id)
                       VALUES (?,?,?,?,?,'Release',?)", [$item['item_id'], $techId, $jobId, $qn, $due, $riId]);
                    // No stock_movement: the tool remains company-owned physical stock.
                } else {
                    add_movement((int)$item['item_id'], -$qn, 'Consumable Out', 'release_items', $riId, $logisticsId);
                }
            }
            if ($requestId) q("UPDATE item_requests SET status = 'Released' WHERE request_id = ? AND status = 'Approved'", [$requestId]);
            audit('RELEASE', 'releases', $releaseId, null, ['job' => $job['job_ref_no'], 'technician_id' => $techId, 'type' => $type, 'lines' => count($lines), 'request_id' => $requestId]);
            return $releaseId;
        })();
    }

    public static function releaseLines(int $releaseId): array
    {
        return q_all("SELECT ri.*, i.name, i.unit_of_measure FROM release_items ri JOIN items i ON i.item_id = ri.item_id WHERE ri.release_id = ?", [$releaseId]);
    }

    public static function pendingFor(int $techId): array
    {
        $rows = q_all("SELECT r.*, j.job_ref_no, j.client_name, u.full_name AS released_name
                       FROM releases r JOIN jobs j ON j.job_id = r.job_id JOIN users u ON u.user_id = r.released_by
                       WHERE r.technician_id = ? AND r.confirm_status = 'Awaiting Confirmation' ORDER BY r.release_id", [$techId]);
        foreach ($rows as &$r) $r['lines'] = self::releaseLines((int)$r['release_id']);
        return $rows;
    }

    public static function confirm(int $releaseId, int $techId, bool $ok, string $note): void
    {
        $r = q_one("SELECT * FROM releases WHERE release_id = ?", [$releaseId]);
        if (!$r || (int)$r['technician_id'] !== $techId) throw new UserError('This release is not addressed to you.');
        if ($r['confirm_status'] !== 'Awaiting Confirmation') throw new UserError('You already responded to this release.');
        if (!$ok && !reason_ok($note)) throw new UserError(reason_msg('Say what is wrong so Logistics can check.'));
        q("UPDATE releases SET confirm_status = ?, confirmed_at = NOW(), dispute_note = ? WHERE release_id = ?",
          [$ok ? 'Confirmed' : 'Disputed', $ok ? null : trim($note), $releaseId]);
        audit($ok ? 'RELEASE_CONFIRM' : 'RELEASE_DISPUTE', 'releases', $releaseId, null, $ok ? null : ['note' => trim($note)]);
    }

    public static function disputedReleases(): array
    {
        return q_all("SELECT r.*, j.job_ref_no, j.client_name, u.full_name AS technician, l.full_name AS released_name,
                             (SELECT GROUP_CONCAT(CONCAT(i.name,' × ',TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM ri.qty))) SEPARATOR ', ')
                              FROM release_items ri JOIN items i ON i.item_id = ri.item_id WHERE ri.release_id = r.release_id) AS items
                      FROM releases r JOIN jobs j ON j.job_id = r.job_id JOIN users u ON u.user_id = r.technician_id JOIN users l ON l.user_id = r.released_by
                      WHERE r.confirm_status = 'Disputed' ORDER BY r.release_id");
    }

    public static function resolveDispute(int $releaseId, string $decision, string $note, int $adminId): void
    {
        if (!in_array($decision, ['Accept Recorded','Reject and Re-release'], true)) throw new UserError('Choose how the discrepancy will be resolved.');
        if (!reason_ok($note)) throw new UserError(reason_msg('Give a resolution note.'));
        tx(function () use ($releaseId, $decision, $note, $adminId) {
            $r = q_one("SELECT * FROM releases WHERE release_id = ? FOR UPDATE", [$releaseId]);
            if (!$r || $r['confirm_status'] !== 'Disputed') throw new UserError('That disputed release is no longer pending.');
            if ((int)$r['released_by'] === $adminId) throw new UserError('The Admin who released this transaction cannot resolve its dispute.');
            q("UPDATE releases SET dispute_resolved_by = ?, dispute_note = CONCAT(COALESCE(dispute_note,''), '\nResolution: ', ?) WHERE release_id = ?", [$adminId, $note, $releaseId]);
            if ($decision === 'Accept Recorded') {
                q("UPDATE releases SET confirm_status = 'Confirmed', confirmed_at = NOW() WHERE release_id = ?", [$releaseId]);
                audit('RELEASE_DISPUTE_RESOLVED', 'releases', $releaseId, null, ['decision'=>$decision,'note'=>$note]);
                return;
            }
            // The original remains visible/locked. The release remains Disputed until a new linked re-release is created.
            audit('RELEASE_DISPUTE_PENDING_RERELEASE', 'releases', $releaseId, null, ['decision'=>$decision,'note'=>$note]);
        });
    }

    public static function undeclaredFor(int $techId): array
    {
        return q_all("SELECT ri.release_item_id, ri.qty, i.name, i.unit_of_measure, i.allows_decimal, j.job_ref_no, r.released_at
                      FROM release_items ri JOIN releases r ON r.release_id = ri.release_id
                      JOIN items i ON i.item_id = ri.item_id JOIN jobs j ON j.job_id = r.job_id
                      LEFT JOIN consumable_leftovers cl ON cl.release_item_id = ri.release_item_id
                      WHERE r.technician_id = ? AND r.release_type = 'Consumable' AND r.confirm_status = 'Confirmed'
                        AND cl.leftover_id IS NULL AND j.billing_status = 'Unbilled'
                      ORDER BY j.job_ref_no, i.name", [$techId]);
    }

    public static function declareLeftover(int $releaseItemId, float $left, int $techId): void
    {
        $ri = q_one("SELECT ri.*, r.technician_id, r.release_type, r.confirm_status, i.name, i.unit_of_measure, i.allows_decimal
                     FROM release_items ri JOIN releases r ON r.release_id = ri.release_id JOIN items i ON i.item_id = ri.item_id
                     WHERE ri.release_item_id = ?", [$releaseItemId]);
        if (!$ri || (int)$ri['technician_id'] !== $techId || $ri['release_type'] !== 'Consumable' || $ri['confirm_status'] !== 'Confirmed') throw new UserError('That line is not yours to declare.');
        if ($left < 0 || $left > (float)$ri['qty']) throw new UserError('Leftovers must be between 0 and ' . qty($ri['qty']) . '.');
        require_whole($left, $ri);
        q("INSERT INTO consumable_leftovers (release_item_id, qty_returned, declared_by) VALUES (?,?,?)", [$releaseItemId, $left, $techId]);
        audit('LEFTOVER_DECLARE', 'consumable_leftovers', (int) db()->lastInsertId(), null, ['item' => $ri['name'], 'qty' => $left]);
    }

    public static function leftoversToReceive(): array
    {
        return q_all("SELECT cl.*, i.name, i.unit_of_measure, j.job_ref_no, u.full_name AS technician
                      FROM consumable_leftovers cl JOIN release_items ri ON ri.release_item_id = cl.release_item_id
                      JOIN releases r ON r.release_id = ri.release_id JOIN items i ON i.item_id = ri.item_id
                      JOIN jobs j ON j.job_id = r.job_id JOIN users u ON u.user_id = cl.declared_by
                      WHERE cl.received_by IS NULL AND cl.qty_returned > 0 ORDER BY cl.leftover_id");
    }

    public static function receiveLeftover(int $leftoverId, int $logisticsId): void
    {
        tx(function () use ($leftoverId, $logisticsId) {
            $cl = q_one("SELECT cl.*, ri.item_id FROM consumable_leftovers cl JOIN release_items ri USING (release_item_id)
                         WHERE cl.leftover_id = ? FOR UPDATE", [$leftoverId]);
            if (!$cl || $cl['received_by'] !== null) throw new UserError('Those leftovers were already received.');
            q("UPDATE consumable_leftovers SET received_by = ? WHERE leftover_id = ?", [$logisticsId, $leftoverId]);
            add_movement((int)$cl['item_id'], (float)$cl['qty_returned'], 'Leftover Back', 'consumable_leftovers', $leftoverId, $logisticsId);
            audit('LEFTOVER_RECEIVE', 'consumable_leftovers', $leftoverId);
        });
    }

    public static function holdings(?int $techId = null): array
    {
        $sql = "SELECT * FROM v_tool_holdings";
        return $techId ? q_all("$sql WHERE technician_id = ? ORDER BY expected_return_date", [$techId])
                       : q_all("$sql ORDER BY is_overdue DESC, expected_return_date");
    }

    /** Logistics records the inspection. The technician must confirm the inspection on their own account before finalization. */
    public static function returnTool(int $assignmentId, float $n, string $condition, string $notes, int $logisticsId): int
    {
        $allowed = ['Good', 'Minor Wear', 'Damaged-Repairable', 'Damaged-Beyond Repair', 'Missing'];
        if (!in_array($condition, $allowed, true)) throw new UserError('Choose the condition.');
        if ($condition !== 'Good' && !reason_ok($notes)) throw new UserError(reason_msg('Describe the condition in the inspection notes.'));

        return tx(function () use ($assignmentId, $n, $condition, $notes, $logisticsId) {
            $a = q_one("SELECT ta.*, i.name, i.allows_decimal, i.unit_of_measure FROM tool_assignments ta JOIN items i ON i.item_id = ta.item_id
                        WHERE ta.assignment_id = ? FOR UPDATE", [$assignmentId]);
            if (!$a || $a['status'] !== 'Out') throw new UserError('That tool is not out.');
            $held = (float)$a['qty_out'] - (float)$a['qty_returned'] - (float)$a['qty_transferred'];
            if ($held <= 0) throw new UserError('That tool is not out.');
            if ($n <= 0 || $n > $held) throw new UserError('Return between 1 and ' . qty($held) . '.');
            require_whole($n, $a);
            $existing = q_val("SELECT 1 FROM tool_returns WHERE assignment_id = ? AND status = 'Pending Inspection'", [$assignmentId]);
            if ($existing) throw new UserError('A return inspection is already waiting for technician confirmation.');
            q("INSERT INTO tool_returns (assignment_id, qty_returned, condition_in, inspection_notes, status, tech_confirm)
               VALUES (?,?,?,?, 'Pending Inspection','Awaiting Confirmation')", [$assignmentId, $n, $condition, trim($notes) ?: null]);
            $trId = (int)db()->lastInsertId();
            audit('TOOL_RETURN_INSPECT', 'tool_returns', $trId, null, ['tool'=>$a['name'],'qty'=>$n,'condition'=>$condition]);
            return $trId;
        });
    }

    public static function pendingReturnsFor(int $techId): array
    {
        return q_all("SELECT tr.*, ta.item_id, i.item_code, i.name AS item_name, i.unit_of_measure, ta.job_id, j.job_ref_no, ta.qty_out,
                              u.full_name AS technician
                       FROM tool_returns tr JOIN tool_assignments ta ON ta.assignment_id = tr.assignment_id
                       JOIN items i ON i.item_id = ta.item_id JOIN jobs j ON j.job_id = ta.job_id JOIN users u ON u.user_id = ta.technician_id
                       WHERE ta.technician_id = ? AND tr.status = 'Pending Inspection' AND tr.tech_confirm = 'Awaiting Confirmation'
                       ORDER BY tr.tool_return_id", [$techId]);
    }

    public static function confirmReturn(int $returnId, int $techId, bool $ok, string $note): void
    {
        tx(function () use ($returnId, $techId, $ok, $note) {
            $tr = q_one("SELECT tr.*, ta.technician_id FROM tool_returns tr JOIN tool_assignments ta ON ta.assignment_id = tr.assignment_id WHERE tr.tool_return_id = ? FOR UPDATE", [$returnId]);
            if (!$tr || (int)$tr['technician_id'] !== $techId) throw new UserError('That return is not yours to confirm.');
            if ($tr['tech_confirm'] !== 'Awaiting Confirmation') throw new UserError('You already responded to this return inspection.');
            if (!$ok && !reason_ok($note)) throw new UserError(reason_msg('Explain the mismatch in the return inspection.'));
            q("UPDATE tool_returns SET tech_confirm = ?, dispute_note = ? WHERE tool_return_id = ?", [$ok ? 'Confirmed':'Disputed', $ok ? null : trim($note), $returnId]);
            if ($ok) self::finalizeReturn($returnId, $techId);
            audit($ok ? 'TOOL_RETURN_CONFIRM' : 'TOOL_RETURN_DISPUTE', 'tool_returns', $returnId, null, $ok ? null : ['note'=>trim($note)]);
        });
    }

    private static function finalizeReturn(int $returnId, int $actorId): void
{
    $tr = q_one("
        SELECT
            tr.tool_return_id,
            tr.assignment_id,
            tr.qty_returned AS return_qty,
            tr.condition_in,
            tr.inspection_notes,
            tr.status AS return_status,
            tr.tech_confirm,
            ta.item_id,
            ta.technician_id,
            ta.job_id,
            ta.qty_out,
            ta.qty_returned AS assignment_qty_returned,
            ta.qty_transferred,
            ta.status AS assignment_status,
            i.name
        FROM tool_returns tr
        JOIN tool_assignments ta
            ON ta.assignment_id = tr.assignment_id
        JOIN items i
            ON i.item_id = ta.item_id
        WHERE tr.tool_return_id = ?
        FOR UPDATE
    ", [$returnId]);

    if (!$tr) {
        throw new UserError('Return inspection not found.');
    }

    if ($tr['tech_confirm'] !== 'Confirmed') {
        throw new UserError('The technician has not confirmed this return.');
    }

    if ($tr['return_status'] === 'Completed') {
        return;
    }

    $held =
        (float)$tr['qty_out']
        - (float)$tr['assignment_qty_returned']
        - (float)$tr['qty_transferred'];

    $returnQty = (float)$tr['return_qty'];

    if ($returnQty <= 0 || $returnQty > $held) {
        throw new UserError(
            'The recorded return exceeds the remaining quantity on the assignment.'
        );
    }

    if (in_array($tr['condition_in'], ['Good', 'Minor Wear'], true)) {

        $remaining = $held - $returnQty;

        q("
            UPDATE tool_assignments
            SET
                qty_returned = qty_returned + ?,
                status = ?
            WHERE assignment_id = ?
        ", [
            $returnQty,
            $remaining > 0 ? 'Out' : 'Returned',
            $tr['assignment_id']
        ]);

    } else {

        q("
            UPDATE tool_assignments
            SET status = 'Under Incident'
            WHERE assignment_id = ?
        ", [
            $tr['assignment_id']
        ]);

        $exists = q_val(
            "SELECT 1 FROM incident_reports WHERE tool_return_id = ?",
            [$returnId]
        );

        if (!$exists) {
            q("
                INSERT INTO incident_reports (
                    item_id,
                    qty,
                    type,
                    source,
                    tool_return_id,
                    employee_id,
                    job_id,
                    date_discovered,
                    description,
                    severity,
                    created_by
                )
                VALUES (
                    ?, ?, ?, 'Tool Return', ?, ?, ?, CURDATE(), ?, ?, ?
                )
            ", [
                $tr['item_id'],
                $returnQty,
                $tr['condition_in'] === 'Missing'
                    ? 'Missing'
                    : 'Damaged',
                $returnId,
                $tr['technician_id'],
                $tr['job_id'],
                $tr['inspection_notes'],
                in_array(
                    $tr['condition_in'],
                    ['Damaged-Beyond Repair', 'Missing'],
                    true
                ) ? 'High' : 'Medium',
                $actorId
            ]);
        }
    }

    q("
        UPDATE tool_returns
        SET
            status = 'Completed',
            inspected_by = ?,
            inspected_at = COALESCE(inspected_at, NOW())
        WHERE tool_return_id = ?
    ", [
        $actorId,
        $returnId
    ]);
}
    // private static function finalizeReturn(int $returnId, int $actorId): void
    // {
    //     $tr = q_one("SELECT tr.*, ta.*, i.name FROM tool_returns tr JOIN tool_assignments ta ON ta.assignment_id = tr.assignment_id JOIN items i ON i.item_id = ta.item_id WHERE tr.tool_return_id = ? FOR UPDATE", [$returnId]);
    //     if (!$tr || $tr['tech_confirm'] !== 'Confirmed' || $tr['status'] === 'Completed') return;
    //     $held = (float)$tr['qty_out'] - (float)$tr['qty_returned'] - (float)$tr['qty_transferred'];
    //     if ((float)$tr['qty_returned'] > $held) throw new UserError('The recorded return exceeds the remaining quantity on the assignment.');
    //     if (in_array($tr['condition_in'], ['Good','Minor Wear'], true)) {
    //         q("UPDATE tool_assignments SET qty_returned = qty_returned + ?, status = ? WHERE assignment_id = ?",
    //           [$tr['qty_returned'], ($held - (float)$tr['qty_returned']) > 0 ? 'Out' : 'Returned', $tr['assignment_id']]);
    //     } else {
    //         q("UPDATE tool_assignments SET status = 'Under Incident' WHERE assignment_id = ?", [$tr['assignment_id']]);
    //         $exists = q_val("SELECT 1 FROM incident_reports WHERE tool_return_id = ?", [$returnId]);
    //         if (!$exists) {
    //             q("INSERT INTO incident_reports (item_id, qty, type, source, tool_return_id, employee_id, job_id, date_discovered, description, severity, created_by)
    //                VALUES (?,?,?,'Tool Return',?,?,?,CURDATE(),?,?,?)", [
    //                 $tr['item_id'], $tr['qty_returned'], $tr['condition_in'] === 'Missing' ? 'Missing' : 'Damaged', $returnId, $tr['technician_id'], $tr['job_id'],
    //                 $tr['inspection_notes'], $tr['condition_in'] === 'Damaged-Beyond Repair' || $tr['condition_in'] === 'Missing' ? 'High' : 'Medium', $actorId,
    //             ]);
    //         }
    //     }
    //     q("UPDATE tool_returns SET status = 'Completed', inspected_by = ?, inspected_at = COALESCE(inspected_at, NOW()) WHERE tool_return_id = ?", [$actorId, $returnId]);
    // }

    public static function disputedReturns(): array
    {
        return q_all("SELECT tr.*, ta.item_id, ta.technician_id, i.name AS item_name, j.job_ref_no, u.full_name AS technician
                       FROM tool_returns tr JOIN tool_assignments ta ON ta.assignment_id = tr.assignment_id
                       JOIN items i ON i.item_id = ta.item_id JOIN jobs j ON j.job_id = ta.job_id JOIN users u ON u.user_id = ta.technician_id
                       WHERE tr.status = 'Pending Inspection' AND tr.tech_confirm = 'Disputed' ORDER BY tr.tool_return_id");
    }

    public static function resolveReturnDispute(int $returnId, string $action, string $note, int $adminId): void
    {
        if (!reason_ok($note)) throw new UserError(reason_msg('Give a resolution note.'));
        tx(function () use ($returnId, $action, $note, $adminId) {
            $tr = q_one("SELECT tr.*, ta.technician_id FROM tool_returns tr JOIN tool_assignments ta ON ta.assignment_id = tr.assignment_id WHERE tr.tool_return_id = ? FOR UPDATE", [$returnId]);
            if (!$tr || $tr['tech_confirm'] !== 'Disputed') throw new UserError('That disputed return is no longer pending.');
            if ($action === 'Accept inspection') {
                q("UPDATE tool_returns SET tech_confirm='Confirmed', dispute_note=CONCAT(COALESCE(dispute_note,''),'\nAdmin resolution: ',?) WHERE tool_return_id=?", [$note,$returnId]);
                self::finalizeReturn($returnId, $adminId);
            } elseif ($action === 'Reject inspection') {
                q("UPDATE tool_returns SET status='Completed', inspected_by=?, inspected_at=NOW(), dispute_note=CONCAT(COALESCE(dispute_note,''),'\nAdmin resolution: ',?) WHERE tool_return_id=?", [$adminId,$note,$returnId]);
            } else throw new UserError('Choose a resolution.');
            audit('RETURN_DISPUTE_RESOLVE','tool_returns',$returnId,null,['action'=>$action,'note'=>$note]);
        });
    }

    public static function requestAdditionalItem(int $techId, int $jobId, int $itemId, float $qty, string $reason): int
    {
        if ($qty <= 0) throw new UserError('Enter a quantity of at least 1.');
        if (!reason_ok($reason)) throw new UserError(reason_msg('Say why you need the extra item'));
        $job = q_one("SELECT * FROM jobs WHERE job_id=?",[$jobId]);
        if (!$job || $job['status'] !== 'Open' || $job['billing_status'] !== 'Unbilled') throw new UserError('Requests require an Open, unbilled job.');
        if (!q_val("SELECT 1 FROM job_technicians WHERE job_id=? AND technician_id=? AND removed_at IS NULL",[$jobId,$techId])) throw new UserError('You are not assigned to that job.');
        $item=q_one("SELECT * FROM items WHERE item_id=? AND is_archived=0",[$itemId]);
        if (!$item) throw new UserError('Choose a valid active item.');
        if ($item['item_type']==='Tool/Equipment' && $item['is_sellable']) throw new UserError($item['name'].' is a tool for sale, not company equipment.');
        require_whole($qty,$item);
        // SOW flow 2.5: the requested quantity cannot exceed what is available in the stockroom.
        $available=(float)q_val("SELECT qty_available FROM v_item_stock WHERE item_id=?",[$itemId]);
        if ($available<=0) throw new UserError($item['name'].' is out of stock right now, so it cannot be requested.');
        if ($qty>$available) throw new UserError('Only '.qty($available).' '.$item['unit_of_measure'].' of '.$item['name'].' '.($available==1?'is':'are').' available. Request '.qty($available).' or less.');
        q("INSERT INTO item_requests (job_id,technician_id,item_id,qty,reason) VALUES (?,?,?,?,?)",[$jobId,$techId,$itemId,$qty,trim($reason)]);
        $id=(int)db()->lastInsertId(); audit('ITEM_REQUEST_CREATE','item_requests',$id,null,['job'=>$job['job_ref_no'],'qty'=>$qty,'item'=>$item['name']]); return $id;
    }

    public static function itemRequests(string $status=''): array
    {
        $sql="SELECT r.*, j.job_ref_no, j.client_name, u.full_name AS technician, i.name AS item_name, i.unit_of_measure, d.full_name AS decided_name
              FROM item_requests r JOIN jobs j ON j.job_id=r.job_id JOIN users u ON u.user_id=r.technician_id JOIN items i ON i.item_id=r.item_id
              LEFT JOIN users d ON d.user_id=r.decided_by WHERE 1=1";
        $p=[]; if($status!==''){ $sql.=" AND r.status=?"; $p[]=$status; } return q_all($sql." ORDER BY r.request_id DESC",$p);
    }

    public static function requestsFor(int $techId): array
    {
        return q_all("SELECT r.*, j.job_ref_no, i.name AS item_name, i.unit_of_measure, d.full_name AS decided_name
                      FROM item_requests r JOIN jobs j ON j.job_id = r.job_id JOIN items i ON i.item_id = r.item_id
                      LEFT JOIN users d ON d.user_id = r.decided_by WHERE r.technician_id = ? ORDER BY r.request_id DESC LIMIT 30", [$techId]);
    }

    /** Return inspections Logistics recorded that are still waiting for the technician's confirmation. */
    public static function returnsAwaitingTechnician(): array
    {
        return q_all("SELECT tr.*, ta.assignment_id, ta.job_id, i.name AS item_name, i.unit_of_measure, j.job_ref_no, u.full_name AS technician
                      FROM tool_returns tr JOIN tool_assignments ta ON ta.assignment_id = tr.assignment_id
                      JOIN items i ON i.item_id = ta.item_id JOIN jobs j ON j.job_id = ta.job_id JOIN users u ON u.user_id = ta.technician_id
                      WHERE tr.status = 'Pending Inspection' ORDER BY tr.tool_return_id");
    }

    public static function decideRequest(int $requestId, bool $approve, string $reason, int $adminId): void
    {
        tx(function() use($requestId,$approve,$reason,$adminId){
            $r=q_one("SELECT r.*, j.job_ref_no FROM item_requests r JOIN jobs j ON j.job_id=r.job_id WHERE r.request_id=? FOR UPDATE",[$requestId]);
            if(!$r || $r['status']!=='Pending') throw new UserError('That request is no longer pending.');
            if((int)$r['technician_id']===$adminId) throw new UserError('An Admin cannot approve their own transaction.');
            if(!$approve && !reason_ok($reason)) throw new UserError(reason_msg('Give a reason for denial.'));
            if($approve){
                $i=q_one("SELECT s.qty_available, s.unit_of_measure, s.name FROM v_item_stock s WHERE s.item_id=?",[$r['item_id']]);
                if((float)$r['qty']>(float)$i['qty_available']) throw new UserError('Only '.qty($i['qty_available']).' '.$i['unit_of_measure'].' of '.$i['name'].' are available now, less than the '.qty($r['qty']).' requested. Deny it with a reason, or wait for a delivery.');
            }
            q("UPDATE item_requests SET status=?, decided_by=?, decided_at=NOW(), decision_reason=? WHERE request_id=?",[$approve?'Approved':'Denied',$adminId,trim($reason)?:null,$requestId]);
            audit($approve?'ITEM_REQUEST_APPROVE':'ITEM_REQUEST_DENY','item_requests',$requestId,null,['reason'=>trim($reason)]);
        });
    }

    /** Transfers addressed to this technician that are waiting for their answer. */
    public static function transfersFor(int $techId): array
    {
        return self::transferList("tt.to_technician_id=? AND tt.status='Awaiting Confirmation'", [$techId]);
    }

    /** Transfers this technician sent: pending ones (cancellable) and the last 14 days of answered ones. */
    public static function transfersSentBy(int $techId): array
    {
        return self::transferList("ta.technician_id=? AND (tt.status='Awaiting Confirmation' OR tt.created_at >= NOW() - INTERVAL 14 DAY)", [$techId]);
    }

    private static function transferList(string $where, array $p): array
    {
        return q_all("SELECT tt.transfer_id, tt.qty, tt.`condition`, tt.notes, tt.status AS transfer_status, tt.created_at, tt.decided_at,
                             i.name AS item_name, i.unit_of_measure, ta.technician_id AS from_tech_id, fu.full_name AS from_technician,
                             tt.to_technician_id, tu.full_name AS to_technician, j.job_ref_no
                      FROM tool_transfers tt JOIN tool_assignments ta ON ta.assignment_id=tt.from_assignment_id
                      JOIN items i ON i.item_id=ta.item_id JOIN users fu ON fu.user_id=ta.technician_id JOIN users tu ON tu.user_id=tt.to_technician_id
                      JOIN jobs j ON j.job_id=tt.job_id
                      WHERE $where ORDER BY tt.transfer_id DESC", $p);
    }

    /** Quantity of an assignment already promised in transfers that are still waiting for an answer. */
    private static function pendingTransferQty(int $assignmentId): float
    {
        return (float) q_val("SELECT COALESCE(SUM(qty),0) FROM tool_transfers WHERE from_assignment_id=? AND status='Awaiting Confirmation'", [$assignmentId]);
    }

    /** The sender withdraws a transfer before the receiver answers it. */
    public static function cancelTransfer(int $transferId, int $techId): void
    {
        tx(function () use ($transferId, $techId) {
            $tt = q_one("SELECT tt.status AS transfer_status, ta.technician_id AS from_tech_id FROM tool_transfers tt
                         JOIN tool_assignments ta ON ta.assignment_id=tt.from_assignment_id WHERE tt.transfer_id=? FOR UPDATE", [$transferId]);
            if (!$tt || (int)$tt['from_tech_id'] !== $techId) throw new UserError('Only the technician who sent this transfer can cancel it.');
            if ($tt['transfer_status'] !== 'Awaiting Confirmation') throw new UserError('This transfer was already ' . strtolower($tt['transfer_status']) . ', so it can no longer be cancelled.');
            q("UPDATE tool_transfers SET status='Cancelled', decided_at=NOW() WHERE transfer_id=?", [$transferId]);
            audit('TOOL_TRANSFER_CANCEL', 'tool_transfers', $transferId);
        });
    }

    public static function createTransfer(int $fromTech, int $assignmentId, int $toTech, float $qty, string $condition, string $notes): int
    {
        if($fromTech===$toTech) throw new UserError('Choose a different receiving technician.');
        if(!in_array($condition,['Good','Minor Wear','Damaged'],true)) throw new UserError('Choose the condition.');
        $a=q_one("SELECT ta.*, i.name, i.allows_decimal FROM tool_assignments ta JOIN items i ON i.item_id=ta.item_id WHERE ta.assignment_id=?",[$assignmentId]);
        if(!$a || (int)$a['technician_id']!==$fromTech || $a['status']!=='Out') throw new UserError('That tool is not currently held by you.');
        $held=(float)$a['qty_out']-(float)$a['qty_returned']-(float)$a['qty_transferred']; $pending=self::pendingTransferQty($assignmentId);
        if($pending>0 && $qty>$held-$pending) throw new UserError('You already have a transfer of this tool waiting for an answer. Cancel it under "Transfers you sent" before sending another.');
        if($qty<=0||$qty>$held) throw new UserError('Transfer quantity is out of range.'); require_whole($qty,$a);
        $job=(int)$a['job_id']; if(!q_val("SELECT 1 FROM job_technicians WHERE job_id=? AND technician_id=? AND removed_at IS NULL",[$job,$toTech])) throw new UserError('The receiving technician must belong to the same open job.');
        q("INSERT INTO tool_transfers (from_assignment_id,to_technician_id,job_id,qty,`condition`,notes) VALUES (?,?,?,?,?,?)",[$assignmentId,$toTech,$job,$qty,$condition,trim($notes)?:null]);
        $id=(int)db()->lastInsertId(); audit('TOOL_TRANSFER_REQUEST','tool_transfers',$id,null,['to'=>$toTech,'qty'=>$qty,'condition'=>$condition]); return $id;
    }

    public static function confirmTransfer(int $transferId, int $techId, bool $ok): void
    {
        tx(function() use($transferId,$techId,$ok){
            $tt=q_one("SELECT ta.*, tt.transfer_id, tt.to_technician_id, tt.qty, tt.status AS transfer_status, tt.job_id AS transfer_job_id, i.name, i.allows_decimal FROM tool_transfers tt JOIN tool_assignments ta ON ta.assignment_id=tt.from_assignment_id JOIN items i ON i.item_id=ta.item_id WHERE tt.transfer_id=? FOR UPDATE",[$transferId]);
            if(!$tt || (int)$tt['to_technician_id']!==$techId) throw new UserError('This transfer is addressed to another technician.');
            if($tt['transfer_status']!=='Awaiting Confirmation') throw new UserError('This transfer was already '.strtolower($tt['transfer_status']).'.');
            if(!$ok){ q("UPDATE tool_transfers SET status='Declined',decided_at=NOW() WHERE transfer_id=?",[$transferId]); audit('TOOL_TRANSFER_DECLINE','tool_transfers',$transferId); return; }
            $held=(float)$tt['qty_out']-(float)$tt['qty_returned']-(float)$tt['qty_transferred']; if($tt['qty']>$held) throw new UserError('Not enough of that tool remains with the sender.');
            q("UPDATE tool_assignments SET qty_transferred=qty_transferred+?, status=? WHERE assignment_id=?",[$tt['qty'],($held-$tt['qty'])>0?'Out':'Transferred',$tt['assignment_id']]);
            q("INSERT INTO tool_assignments (item_id,technician_id,job_id,qty_out,expected_return_date,source,status) VALUES (?,?,?,?,?,'Transfer','Out')",[$tt['item_id'],$techId,$tt['job_id'],$tt['qty'],$tt['expected_return_date']]);
            $newId=(int)db()->lastInsertId();
            q("UPDATE tool_transfers SET status='Confirmed',decided_at=NOW(),new_assignment_id=? WHERE transfer_id=?",[$newId,$transferId]);
            audit('TOOL_TRANSFER_CONFIRM','tool_transfers',$transferId,null,['new_assignment'=>$newId]);
        });
    }

    public static function requestExtension(int $techId,int $assignmentId,string $newDate,string $reason): int
    {
        $a=q_one("SELECT * FROM tool_assignments WHERE assignment_id=?",[$assignmentId]);
        if(!$a || (int)$a['technician_id']!==$techId || $a['status']!=='Out') throw new UserError('That tool is not currently assigned to you.');
        if($newDate<=$a['expected_return_date']) throw new UserError('The new date must be later than the current return date.');
        if(!reason_ok($reason)) throw new UserError(reason_msg('Give a reason for the extension.'));
        if(q_val("SELECT 1 FROM return_date_extensions WHERE assignment_id=? AND status='Pending'",[$assignmentId])) throw new UserError('An extension request is already pending.');
        q("INSERT INTO return_date_extensions (assignment_id,old_date,new_date,reason,requested_by) VALUES (?,?,?,?,?)",[$assignmentId,$a['expected_return_date'],$newDate,trim($reason),$techId]);
        $id=(int)db()->lastInsertId(); audit('RETURN_EXTENSION_REQUEST','return_date_extensions',$id,null,['new_date'=>$newDate]); return $id;
    }

    public static function extensions(string $status='Pending'): array
    {
        return q_all("SELECT r.*, ta.item_id, i.name AS item_name, u.full_name AS technician, j.job_ref_no, d.full_name AS decided_name
                      FROM return_date_extensions r JOIN tool_assignments ta ON ta.assignment_id=r.assignment_id JOIN items i ON i.item_id=ta.item_id
                      JOIN users u ON u.user_id=r.requested_by JOIN jobs j ON j.job_id=ta.job_id LEFT JOIN users d ON d.user_id=r.decided_by
                      WHERE r.status=? ORDER BY r.extension_id DESC",[$status]);
    }

    public static function decideExtension(int $extensionId,bool $approve,string $reason,int $adminId): void
    {
        tx(function() use($extensionId,$approve,$reason,$adminId){
            $r=q_one("SELECT r.*,ta.technician_id,ta.status AS assignment_status FROM return_date_extensions r JOIN tool_assignments ta ON ta.assignment_id=r.assignment_id WHERE r.extension_id=? FOR UPDATE",[$extensionId]);
            if(!$r || $r['status']!=='Pending') throw new UserError('That extension request is no longer pending.');
            if((int)$r['requested_by']===$adminId) throw new UserError('An Admin cannot approve their own request.');
            if(!$approve && !reason_ok($reason)) throw new UserError(reason_msg('Give a denial reason.'));
            q("UPDATE return_date_extensions SET status=?,decided_by=?,decided_at=NOW(),decision_reason=? WHERE extension_id=?",[$approve?'Approved':'Denied',$adminId,trim($reason)?:null,$extensionId]);
            if($approve) q("UPDATE tool_assignments SET expected_return_date=? WHERE assignment_id=? AND status IN ('Out','Under Incident')",[$r['new_date'],$r['assignment_id']]);
            audit($approve?'RETURN_EXTENSION_APPROVE':'RETURN_EXTENSION_DENY','return_date_extensions',$extensionId,null,['new_date'=>$r['new_date'],'reason'=>trim($reason)]);
        });
    }

    public static function openIncidents(): array
    {
        return q_all("SELECT ir.*, i.name AS item_name, u.full_name AS employee, j.job_ref_no
                      FROM incident_reports ir JOIN items i ON i.item_id = ir.item_id
                      LEFT JOIN users u ON u.user_id = ir.employee_id LEFT JOIN jobs j ON j.job_id = ir.job_id
                      WHERE ir.status <> 'Resolved' ORDER BY ir.incident_id");
    }

    public static function resolveIncident(int $incidentId, string $resolution, ?float $charge, int $adminId): void
    {
        if (!in_array($resolution, ['Repaired', 'Written Off'], true)) throw new UserError('Choose Repaired or Written Off.');
        tx(function () use ($incidentId, $resolution, $charge, $adminId) {
            $ir = q_one("SELECT * FROM incident_reports WHERE incident_id = ? FOR UPDATE", [$incidentId]);
            if (!$ir || $ir['status'] === 'Resolved') throw new UserError('That incident is already resolved.');
            if ($ir['created_by'] === $adminId) throw new UserError('A different Admin must resolve this incident.');
            q("UPDATE incident_reports SET status = 'Resolved', resolution = ?, charge_amount = ?, resolved_by = ?, resolved_at = NOW() WHERE incident_id = ?",
              [$resolution, $charge ?: null, $adminId, $incidentId]);

            if ($ir['tool_return_id']) {
                $a = q_one("SELECT ta.* FROM tool_assignments ta JOIN tool_returns tr ON tr.assignment_id = ta.assignment_id
                            WHERE tr.tool_return_id = ? FOR UPDATE", [$ir['tool_return_id']]);
                if ($a) {
                    $left = (float)$a['qty_out'] - (float)$a['qty_returned'] - (float)$a['qty_transferred'] - (float)$ir['qty'];
                    q("UPDATE tool_assignments SET qty_returned = qty_returned + ?, status = ? WHERE assignment_id = ?",
                      [$ir['qty'], $left > 0 ? 'Out' : 'Returned', $a['assignment_id']]);
                }
            }
            if ($resolution === 'Repaired') {
                // Tool physical quantity was never reduced when assigned; repaired simply restores availability by clearing custody.
            } elseif ($resolution === 'Written Off') {
                add_movement((int)$ir['item_id'], -(float)$ir['qty'], 'Write Off', 'incident_reports', $incidentId, $adminId);
            }
            audit('INCIDENT_RESOLVE', 'incident_reports', $incidentId, null, ['resolution' => $resolution, 'charge' => $charge]);
        });
    }
}
