<?php
// Sales corrections, returns, drawer handover, and downpayment corrections.

class SalesWorkflowService
{
    public static function saleLinesForReturn(int $saleId): array
    {
        return q_all("SELECT sl.*, i.name, i.unit_of_measure,
                             sl.qty - COALESCE((SELECT SUM(sri.qty) FROM sales_return_items sri JOIN sales_returns sr ON sr.sales_return_id=sri.sales_return_id WHERE sr.sale_id=sl.sale_id AND sr.status='Approved' AND sri.sale_line_id=sl.sale_line_id),0) AS qty_returnable
                      FROM sale_lines sl LEFT JOIN items i ON i.item_id=sl.item_id
                      WHERE sl.sale_id=? AND sl.line_type NOT IN ('Job Material','Service Fee','Cancellation Fee') ORDER BY sl.sale_line_id",[$saleId]);
    }

    public static function requestVoid(int $uid, ?int $saleId, ?int $dpId, string $reason): int
    {
        if (!reason_ok($reason)) throw new UserError(reason_msg('Give a reason for the void request.'));
        if (($saleId===null) === ($dpId===null)) throw new UserError('Choose exactly one transaction to void.');
        $today = today();
        if ($saleId) {
            $s=q_one("SELECT s.*, ds.sales_day_id, d.business_date FROM sales s JOIN drawer_sessions ds ON ds.session_id=s.session_id JOIN sales_days d ON d.sales_day_id=ds.sales_day_id WHERE s.sale_id=?",[$saleId]);
            if(!$s) throw new UserError('Sale not found.');
            if((int)$s['recorded_by']!==$uid) throw new UserError('Sales Staff may request same-day voids for their own entries.');
            if($s['business_date']!==$today || $s['status']!=='Completed') throw new UserError('Only a completed sale from today may be voided.');
        } else {
            $d=q_one("SELECT dp.*, ds.sales_day_id, sd.business_date FROM downpayments dp JOIN drawer_sessions ds ON ds.session_id=dp.session_id JOIN sales_days sd ON sd.sales_day_id=ds.sales_day_id WHERE dp.downpayment_id=?",[$dpId]);
            if(!$d) throw new UserError('Downpayment not found.');
            if((int)$d['recorded_by']!==$uid) throw new UserError('You may only request a void for your own entry.');
            if($d['business_date']!==$today || $d['status']!=='Recorded') throw new UserError('Only a recorded downpayment from today may be voided.');
        }
        if($saleId !== null) {
            if(q_val("SELECT 1 FROM void_requests WHERE sale_id=? AND status='Pending'",[$saleId])) throw new UserError('A void request is already pending.');
        } else {
            if(q_val("SELECT 1 FROM void_requests WHERE downpayment_id=? AND status='Pending'",[$dpId])) throw new UserError('A void request is already pending.');
        }
        q("INSERT INTO void_requests (sale_id,downpayment_id,reason,requested_by) VALUES (?,?,?,?)",[$saleId,$dpId,trim($reason),$uid]);
        $id=(int)db()->lastInsertId(); audit('VOID_REQUEST','void_requests',$id,null,['sale'=>$saleId,'downpayment'=>$dpId]); return $id;
    }

    public static function pendingVoids(): array
    {
        return q_all("SELECT vr.*, u.full_name AS requester, s.sale_ref_no, s.or_number, s.sale_type, s.status AS sale_status, d.or_number AS dp_or,
                             j.job_ref_no
                      FROM void_requests vr JOIN users u ON u.user_id=vr.requested_by
                      LEFT JOIN sales s ON s.sale_id=vr.sale_id LEFT JOIN downpayments d ON d.downpayment_id=vr.downpayment_id LEFT JOIN jobs j ON j.job_id=s.job_id
                      WHERE vr.status='Pending' ORDER BY vr.void_id DESC");
    }

    public static function decideVoid(int $voidId,bool $approve,string $notes,int $adminId): void
    {
        tx(function() use($voidId,$approve,$notes,$adminId){
            $v=q_one("SELECT * FROM void_requests WHERE void_id=? FOR UPDATE",[$voidId]);
            if(!$v || $v['status']!=='Pending') throw new UserError('That void request is no longer pending.');
            if((int)$v['requested_by']===$adminId) throw new UserError('A different Admin must approve the void.');
            if(!$approve && !reason_ok($notes)) throw new UserError(reason_msg('Give a rejection reason.'));
            if(!$approve){q("UPDATE void_requests SET status='Rejected',decided_by=?,decided_at=NOW(),decision_notes=? WHERE void_id=?",[$adminId,trim($notes),$voidId]); audit('VOID_REJECT','void_requests',$voidId); return;}
            if($v['sale_id']) self::approveSaleVoid((int)$v['sale_id'],$adminId);
            else self::approveDownpaymentVoid((int)$v['downpayment_id'],$adminId);
            q("UPDATE void_requests SET status='Approved',decided_by=?,decided_at=NOW(),decision_notes=? WHERE void_id=?",[$adminId,trim($notes)?:null,$voidId]);
            audit('VOID_APPROVE','void_requests',$voidId);
        });
    }

    private static function approveSaleVoid(int $saleId,int $adminId): void
    {
        $s=q_one("SELECT s.*, ds.sales_day_id FROM sales s JOIN drawer_sessions ds ON ds.session_id=s.session_id WHERE s.sale_id=? FOR UPDATE",[$saleId]);
        if(!$s || $s['status']!=='Completed') throw new UserError('That sale cannot be voided.');
        foreach(q_all("SELECT sl.* FROM sale_lines sl WHERE sl.sale_id=?",[$saleId]) as $l){
            if(in_array($l['line_type'],['Item','Other Item'],true) && $l['item_id']) add_movement((int)$l['item_id'],(float)$l['qty'],'Void Restock','sale_lines',(int)$l['sale_line_id'],$adminId);
        }
        if($s['sale_type']==='Job Billing' && $s['job_id']){
            q("UPDATE downpayments SET status='Recorded',applied_sale_id=NULL,applied_amount=NULL WHERE applied_sale_id=? AND status='Applied'",[$saleId]);
            q("UPDATE jobs SET billing_status='Unbilled' WHERE job_id=?",[$s['job_id']]);
        }
        q("UPDATE sales SET status='Voided' WHERE sale_id=?",[$saleId]);
    }

    private static function approveDownpaymentVoid(int $dpId,int $adminId): void
    {
        $d=q_one("SELECT * FROM downpayments WHERE downpayment_id=? FOR UPDATE",[$dpId]);
        if(!$d || $d['status']!=='Recorded') throw new UserError('That downpayment cannot be voided.');
        q("UPDATE downpayments SET status='Voided' WHERE downpayment_id=?",[$dpId]);
    }

    public static function requestSalesReturn(int $uid,int $saleId,array $lines,string $reason): int
    {
        if(!has_role('Sales','Executive Officer')) throw new UserError('Only Sales Staff or an Executive Officer can request a customer return.');
        if(!reason_ok($reason)) throw new UserError(reason_msg('Give a return reason.'));
        $s=q_one("SELECT s.*, ds.business_date FROM sales s JOIN (SELECT ds.session_id, sd.business_date FROM drawer_sessions ds JOIN sales_days sd ON sd.sales_day_id=ds.sales_day_id) ds ON ds.session_id=s.session_id WHERE s.sale_id=?",[$saleId]);
        if(!$s) throw new UserError('Sale not found.');
        if((int)$s['recorded_by']!==$uid && !has_role('Executive Officer')) throw new UserError('You may only request a return for a sale you recorded.');
        if(!in_array($s['status'],['Completed','Partially Returned'],true)) throw new UserError('That sale cannot be returned.');
        $age=(strtotime(today())-strtotime($s['business_date']))/86400; $period=(int)setting('sales_return_days',7);
        if($age>$period) throw new UserError("The return period of $period day(s) has passed.");
        if(!$lines) throw new UserError('Select at least one item line to return.');
        $saleLines=[]; foreach(self::saleLinesForReturn($saleId) as $l) $saleLines[(int)$l['sale_line_id']]=$l;
        $prepared=[];$total=0;
        foreach($lines as $row){
            $lid=(int)($row['sale_line_id']??0); $q=to_num($row['qty']??0); if($q<=0) continue;
            if(!isset($saleLines[$lid])) throw new UserError('One of the selected sale lines cannot be returned.');
            $sl=$saleLines[$lid]; if($q>(float)$sl['qty_returnable']) throw new UserError('Return quantity exceeds the remaining sold quantity for '.$sl['name'].'.');
            $cond=in_array($row['condition']??'', ['Resellable','Damaged/Defective'],true)?$row['condition']:'Resellable';
            $grossLine=(float)$sl['line_total']; $discountFactor=(float)$s['gross_amount']>0 ? max(0,(float)$s['gross_amount']-(float)$s['discount_amount'])/(float)$s['gross_amount'] : 1;
            $unitRefund=round(((float)$sl['unit_price']*$discountFactor)*(1+(float)$s['vat_rate']/100),2); $refund=round($unitRefund*$q,2); $total+=$refund;
            $prepared[]=['sale_line_id'=>$lid,'item_id'=>(int)$sl['item_id'],'qty'=>$q,'condition'=>$cond,'refund'=>$refund];
        }
        if(!$prepared) throw new UserError('Add at least one return quantity.');
        q("INSERT INTO sales_returns (sale_id,reason,refund_amount,recorded_by) VALUES (?,?,?,?)",[$saleId,trim($reason),$total,$uid]);
        $id=(int)db()->lastInsertId();
        foreach($prepared as $r) q("INSERT INTO sales_return_items (sales_return_id,sale_line_id,item_id,qty,item_condition,refund_amount) VALUES (?,?,?,?,?,?)",[$id,$r['sale_line_id'],$r['item_id'],$r['qty'],$r['condition'],$r['refund']]);
        audit('SALES_RETURN_REQUEST','sales_returns',$id,null,['sale'=>$s['sale_ref_no'],'refund'=>$total]); return $id;
    }

    public static function pendingReturns(): array
    {
        return q_all("SELECT sr.*, s.sale_ref_no, s.or_number, s.customer_name, u.full_name AS requester
                      FROM sales_returns sr LEFT JOIN sales s ON s.sale_id=sr.sale_id JOIN users u ON u.user_id=sr.recorded_by
                      WHERE sr.status='Pending' ORDER BY sr.sales_return_id DESC");
    }

    public static function returnDetail(int $id): array
    {
        $r=q_one("SELECT sr.*, s.sale_ref_no,s.or_number,s.customer_name,s.gross_amount,s.discount_amount,s.vat_rate,u.full_name AS requester
                   FROM sales_returns sr LEFT JOIN sales s ON s.sale_id=sr.sale_id JOIN users u ON u.user_id=sr.recorded_by WHERE sr.sales_return_id=?",[$id]);
        if(!$r) throw new UserError('Return not found.');
        $r['lines']=q_all("SELECT sri.*, i.name, i.unit_of_measure FROM sales_return_items sri JOIN items i ON i.item_id=sri.item_id WHERE sri.sales_return_id=?",[$id]);
        $r['refund']=q_one("SELECT * FROM refunds WHERE sales_return_id=?",[$id]); return $r;
    }

    public static function decideSalesReturn(int $returnId,bool $approve,string $reason,int $adminId): void
    {
        $r=q_one("SELECT * FROM sales_returns WHERE sales_return_id=?",[$returnId]); if(!$r||$r['status']!=='Pending') throw new UserError('That return is no longer pending.');
        if((int)$r['recorded_by']===$adminId) throw new UserError('A different Admin must approve the return.');
        if(!$approve && !reason_ok($reason)) throw new UserError(reason_msg('Give a rejection reason.'));
        q("UPDATE sales_returns SET status=?,approved_by=? WHERE sales_return_id=?",[$approve?'Approved':'Rejected',$adminId,$returnId]);
        audit($approve?'SALES_RETURN_APPROVE':'SALES_RETURN_REJECT','sales_returns',$returnId,null,['reason'=>trim($reason)]);
    }

    public static function processSalesRefund(int $returnId,int $uid,string $method,string $ref,string $adminEmployee,string $adminPassword): void
    {
        tx(function() use($returnId,$uid,$method,$ref,$adminEmployee,$adminPassword){
            $r=q_one("SELECT sr.*,s.sale_id,s.status AS sale_status FROM sales_returns sr JOIN sales s ON s.sale_id=sr.sale_id WHERE sr.sales_return_id=? FOR UPDATE",[$returnId]);
            if(!$r||$r['status']!=='Approved') throw new UserError('The return must be approved before the refund is processed.');
            if(q_val("SELECT 1 FROM refunds WHERE sales_return_id=?",[$returnId])) throw new UserError('That return has already been refunded.');
            $s=SalesService::requireSession($uid); $admin=verify_admin($adminEmployee,$adminPassword); if((int)$admin['user_id']===$uid) throw new UserError('Refund approval must be a different Admin account.');
            if(!in_array($method,['Cash','GCash','Bank Transfer'],true)) throw new UserError('Choose a refund method.'); if($method!=='Cash'&&trim($ref)==='') throw new UserError('A reference is required for non-cash refunds.');
            q("INSERT INTO refunds (refund_type,sales_return_id,sale_id,amount,method,reference_no,session_id,approved_by,processed_by) VALUES ('Sales Return',?,?,?,?,?,?,?,?)",[$returnId,$r['sale_id'],$r['refund_amount'],$method,trim($ref)?:null,$s['session_id'],$admin['user_id'],$uid]);
            foreach(q_all("SELECT * FROM sales_return_items WHERE sales_return_id=?",[$returnId]) as $line){
                if($line['item_condition']==='Resellable') add_movement((int)$line['item_id'],(float)$line['qty'],'Sales Return Restock','sales_return_items',(int)$line['sales_return_item_id'],$uid);
                else q("INSERT INTO incident_reports (item_id,qty,type,source,sales_return_item_id,employee_id,job_id,date_discovered,description,severity,created_by) SELECT item_id,qty,'Damaged','Sales Return',sales_return_item_id,s.recorded_by,s.job_id,CURDATE(),CONCAT('Customer return: ',?), 'Medium', ? FROM sales_return_items sri JOIN sales s ON s.sale_id=? WHERE sri.sales_return_item_id=?",[trim($r['reason']),$uid,$r['sale_id'],$line['sales_return_item_id']]);
            }
            $remaining=(float)q_val("SELECT COALESCE(SUM(sl.qty),0)-COALESCE((SELECT SUM(sri.qty) FROM sales_return_items sri JOIN sales_returns sr2 ON sr2.sales_return_id=sri.sales_return_id WHERE sr2.sale_id=? AND sr2.status='Approved'),0) FROM sale_lines sl WHERE sl.sale_id=? AND sl.line_type NOT IN ('Job Material','Service Fee','Cancellation Fee')",[$r['sale_id'],$r['sale_id']]);
            q("UPDATE sales SET status=? WHERE sale_id=?",[$remaining>0?'Partially Returned':'Fully Returned',$r['sale_id']]);
            audit('SALES_REFUND_PROCESS','refunds',(int)db()->lastInsertId(),null,['return'=>$returnId,'amount'=>$r['refund_amount']]);
        });
    }

    public static function openHandover(int $uid,int $incomingUid,float $counted,string $why): void
    {
        if($incomingUid===$uid) throw new UserError('Choose a different incoming Sales Staff.');
        $in=q_one("SELECT user_id FROM users WHERE user_id=? AND role='Sales' AND status='Active'",[$incomingUid]); if(!$in) throw new UserError('Choose an active Sales Staff account.');
        tx(function() use($uid,$incomingUid,$counted,$why){
            $s=SalesService::mySession($uid); if(!$s) throw new UserError('Open a drawer first.');
            if($s['handover_confirm']!==null) throw new UserError('This drawer already has a handover in progress.');
            $expected=(float)SalesService::sessionCash((int)$s['session_id'])['expected_cash']; $diff=round($counted-$expected,2); if($diff!=0&&!reason_ok($why)) throw new UserError(reason_msg('Explain the cash difference before starting handover.'));
            q("UPDATE drawer_sessions SET expected_cash=?,counted_cash=?,difference=?,difference_explanation=?,close_type='Handover',handover_to_user_id=?,handover_confirm='Awaiting Confirmation' WHERE session_id=?",[$expected,$counted,$diff,trim($why)?:null,$incomingUid,$s['session_id']]);
            audit('DRAWER_HANDOVER_START','drawer_sessions',$s['session_id'],null,['incoming'=>$incomingUid,'counted'=>$counted,'difference'=>$diff]);
        });
    }

    public static function pendingHandoverFor(int $uid): array
    {
        return q_all("SELECT ds.*,u.full_name AS outgoing FROM drawer_sessions ds JOIN users u ON u.user_id=ds.sales_staff_id WHERE ds.handover_to_user_id=? AND ds.handover_confirm='Awaiting Confirmation'",[$uid]);
    }

    public static function decideHandover(int $sessionId,int $uid,bool $ok,string $why): void
    {
        tx(function() use($sessionId,$uid,$ok,$why){
            $s=q_one("SELECT ds.*,d.status AS day_status FROM drawer_sessions ds JOIN sales_days d ON d.sales_day_id=ds.sales_day_id WHERE ds.session_id=? FOR UPDATE",[$sessionId]);
            if(!$s||(int)$s['handover_to_user_id']!==$uid||$s['handover_confirm']!=='Awaiting Confirmation') throw new UserError('That handover is not waiting for you.');
            if(!$ok&&!reason_ok($why)) throw new UserError(reason_msg('Give a reason for disputing the handover.'));
            if($ok){
                if(q_val("SELECT 1 FROM drawer_sessions WHERE sales_staff_id=? AND closed_at IS NULL",[$uid])) throw new UserError('You already have an open drawer.');
                q("INSERT INTO drawer_sessions (sales_day_id,drawer_no,sales_staff_id,opening_float) VALUES (?,?,?,?)",[$s['sales_day_id'],$s['drawer_no'],$uid,$s['counted_cash']]);
                $next=(int)db()->lastInsertId(); q("UPDATE drawer_sessions SET next_session_id=?,handover_confirm='Confirmed',closed_at=NOW() WHERE session_id=?",[$next,$sessionId]); audit('DRAWER_HANDOVER_CONFIRM','drawer_sessions',$sessionId,null,['next_session'=>$next]);
            } else { q("UPDATE drawer_sessions SET handover_confirm='Disputed',difference_explanation=CONCAT(COALESCE(difference_explanation,''),'\nIncoming dispute: ',?) WHERE session_id=?",[$why,$sessionId]); audit('DRAWER_HANDOVER_DISPUTE','drawer_sessions',$sessionId,null,['note'=>$why]); }
        });
    }

    public static function settleHandoverDispute(int $sessionId,float $finalCount,string $why,int $adminId): void
    {
        if(!reason_ok($why)) throw new UserError(reason_msg('Give a settlement explanation.'));
        tx(function() use($sessionId,$finalCount,$why,$adminId){
            $s=q_one("SELECT * FROM drawer_sessions WHERE session_id=? FOR UPDATE",[$sessionId]);
            if(!$s||$s['handover_confirm']!=='Disputed') throw new UserError('That handover is not disputed.');
            $expected=(float)($s['expected_cash']??0); $diff=round($finalCount-$expected,2);
            q("INSERT INTO drawer_sessions (sales_day_id,drawer_no,sales_staff_id,opening_float) VALUES (?,?,?,?)",[$s['sales_day_id'],$s['drawer_no'],$s['handover_to_user_id'],$finalCount]);
            $next=(int)db()->lastInsertId(); q("UPDATE drawer_sessions SET next_session_id=?,handover_confirm='Confirmed',counted_cash=?,difference=?,difference_explanation=CONCAT(COALESCE(difference_explanation,''),'\nAdmin settlement: ',?),closed_at=NOW() WHERE session_id=?",[$next,$finalCount,$diff,$why,$sessionId]);
            audit('DRAWER_HANDOVER_SETTLE','drawer_sessions',$sessionId,null,['next_session'=>$next,'final_count'=>$finalCount,'reason'=>$why]);
        });
    }

    public static function requestCancellationRefund(int $uid, int $jobId, string $reason): int
    {
        if (!reason_ok($reason)) throw new UserError(reason_msg('Give a reason for the cancellation refund request.'));
        $j = q_one("SELECT * FROM jobs WHERE job_id=?", [$jobId]);
        if (!$j || $j['status'] !== 'Open') throw new UserError('Only open jobs can request a cancellation refund.');
        $dps = q_all("SELECT * FROM downpayments WHERE job_id=? AND status='Recorded' ORDER BY downpayment_id", [$jobId]);
        if (!$dps) throw new UserError('There are no recorded downpayments to refund for this job.');
        $created = 0;
        foreach ($dps as $dp) {
            $existing = q_one("SELECT request_id,status FROM cancellation_refund_requests WHERE downpayment_id=?", [(int)$dp['downpayment_id']]);
            if ($existing && in_array($existing['status'], ['Pending','Approved','Processed'], true)) continue;
            if ($existing && $existing['status'] === 'Rejected') {
                q("UPDATE cancellation_refund_requests SET job_id=?,amount=?,reason=?,status='Pending',requested_by=?,requested_at=NOW(),decided_by=NULL,decided_at=NULL,decision_reason=NULL,processed_by=NULL,processed_at=NULL WHERE request_id=?", [
                    $jobId, $dp['amount'], trim($reason), $uid, (int)$existing['request_id']
                ]);
                $created += 1;
                continue;
            }
            q("INSERT INTO cancellation_refund_requests (downpayment_id,job_id,amount,reason,requested_by) VALUES (?,?,?,?,?)", [
                $dp['downpayment_id'], $jobId, $dp['amount'], trim($reason), $uid
            ]);
            $created += 1;
        }
        if ($created === 0) throw new UserError('A cancellation refund request is already pending or completed for these downpayments.');
        $first = (int)q_val("SELECT MIN(request_id) FROM cancellation_refund_requests WHERE job_id=? AND status='Pending'", [$jobId]);
        audit('CANCELLATION_REFUND_REQUEST','cancellation_refund_requests',$first,null,['job_id'=>$jobId,'count'=>$created,'reason'=>trim($reason)]);
        return $first;
    }

    public static function pendingCancellationRefunds(): array
    {
        return q_all("SELECT r.*, dp.or_number, j.job_ref_no, j.client_name, u.full_name AS requester
                       FROM cancellation_refund_requests r
                       JOIN downpayments dp ON dp.downpayment_id=r.downpayment_id
                       JOIN jobs j ON j.job_id=r.job_id
                       JOIN users u ON u.user_id=r.requested_by
                       WHERE r.status='Pending' ORDER BY r.request_id DESC");
    }

    public static function cancellationRefundsForProcessing(): array
    {
        return q_all("SELECT r.*,dp.or_number,j.job_ref_no,j.client_name,u.full_name AS requester
                      FROM cancellation_refund_requests r
                      JOIN downpayments dp ON dp.downpayment_id=r.downpayment_id
                      JOIN jobs j ON j.job_id=r.job_id
                      JOIN users u ON u.user_id=r.requested_by
                      WHERE r.status IN ('Approved','Processed') ORDER BY r.request_id DESC LIMIT 100");
    }

    public static function decideCancellationRefund(int $requestId, bool $approve, string $reason, int $adminId): void
    {
        tx(function() use($requestId,$approve,$reason,$adminId){
            $r=q_one("SELECT * FROM cancellation_refund_requests WHERE request_id=? FOR UPDATE",[$requestId]);
            if(!$r || $r['status']!=='Pending') throw new UserError('That cancellation refund request is no longer pending.');
            if((int)$r['requested_by']===$adminId) throw new UserError('A different Executive Officer must approve the refund request.');
            if(!$approve && !reason_ok($reason)) throw new UserError(reason_msg('Give a rejection reason.'));
            q("UPDATE cancellation_refund_requests SET status=?,decided_by=?,decided_at=NOW(),decision_reason=? WHERE request_id=?",[$approve?'Approved':'Rejected',$adminId,trim($reason)?:null,$requestId]);
            audit($approve?'CANCELLATION_REFUND_APPROVE':'CANCELLATION_REFUND_REJECT','cancellation_refund_requests',$requestId,null,['reason'=>trim($reason)]);
        });
    }

    public static function processCancellationRefund(int $requestId, int $uid, string $method, string $ref, string $adminEmployee, string $adminPassword): void
    {
        tx(function() use($requestId,$uid,$method,$ref,$adminEmployee,$adminPassword){
            $r=q_one("SELECT r.*,dp.status AS dp_status,j.status AS job_status,j.billing_status FROM cancellation_refund_requests r JOIN downpayments dp ON dp.downpayment_id=r.downpayment_id JOIN jobs j ON j.job_id=r.job_id WHERE r.request_id=? FOR UPDATE",[$requestId]);
            if(!$r || $r['status']!=='Approved') throw new UserError('The cancellation refund must be approved before processing.');
            if($r['dp_status']!=='Recorded') throw new UserError('This downpayment is no longer refundable.');
            $s=SalesService::requireSession($uid);
            $admin=verify_admin($adminEmployee,$adminPassword);
            if((int)$admin['user_id']===$uid) throw new UserError('Refund approval must be a different Executive Officer account.');
            if(!in_array($method,['Cash','GCash','Bank Transfer'],true)) throw new UserError('Choose Cash, GCash, or Bank Transfer.');
            if($method!=='Cash'&&trim($ref)==='') throw new UserError('A reference is required for non-cash refunds.');
            q("INSERT INTO refunds (refund_type,downpayment_id,job_id,amount,method,reference_no,session_id,approved_by,processed_by) VALUES ('Cancellation Refund',?,?,?,?,?,?,?,?)",[(int)$r['downpayment_id'],(int)$r['job_id'],(float)$r['amount'],$method,trim($ref)?:null,$s['session_id'],$admin['user_id'],$uid]);
            q("UPDATE downpayments SET status='Refunded' WHERE downpayment_id=?",[(int)$r['downpayment_id']]);
            q("UPDATE cancellation_refund_requests SET status='Processed',processed_by=?,processed_at=NOW() WHERE request_id=?",[$uid,$requestId]);
            audit('CANCELLATION_REFUND_PROCESS','refunds',(int)db()->lastInsertId(),null,['request_id'=>$requestId,'job_id'=>$r['job_id'],'amount'=>$r['amount']]);
        });
    }

    public static function requestDownpaymentCorrection(int $uid,int $dpId,string $action,?int $newJobId,string $reason): int
    {
        if(!in_array($action,['Move','Refund'],true)) throw new UserError('Choose Move or Refund.'); if(!reason_ok($reason)) throw new UserError(reason_msg('Give a reason.'));
        $d=q_one("SELECT * FROM downpayments WHERE downpayment_id=?",[$dpId]); if(!$d||$d['status']!=='Recorded') throw new UserError('That downpayment can no longer be corrected.');
        if($action==='Move' && !$newJobId) throw new UserError('Choose the destination job.');
        if($action==='Move' && $newJobId===$d['job_id']) throw new UserError('Choose a different job.');
        q("INSERT INTO downpayment_corrections (downpayment_id,action,old_job_id,new_job_id,reason,requested_by) VALUES (?,?,?,?,?,?)",[$dpId,$action,$d['job_id'],$newJobId,trim($reason),$uid]);
        $id=(int)db()->lastInsertId(); audit('DOWNPAYMENT_CORRECTION_REQUEST','downpayment_corrections',$id); return $id;
    }

    public static function pendingDownpaymentCorrections(): array
    {
        return q_all("SELECT c.*,dp.or_number,dp.amount,j.job_ref_no AS old_job_ref,nj.job_ref_no AS new_job_ref,u.full_name AS requester
                      FROM downpayment_corrections c JOIN downpayments dp ON dp.downpayment_id=c.downpayment_id JOIN jobs j ON j.job_id=c.old_job_id
                      LEFT JOIN jobs nj ON nj.job_id=c.new_job_id JOIN users u ON u.user_id=c.requested_by WHERE c.status='Pending' ORDER BY c.correction_id DESC");
    }

    public static function decideDownpaymentCorrection(int $id,bool $approve,string $reason,int $adminId): void
    {
        tx(function() use($id,$approve,$reason,$adminId){
            $c=q_one("SELECT * FROM downpayment_corrections WHERE correction_id=? FOR UPDATE",[$id]); if(!$c||$c['status']!=='Pending') throw new UserError('That correction is no longer pending.'); if((int)$c['requested_by']===$adminId) throw new UserError('A different Admin must approve this correction.'); if(!$approve&&!reason_ok($reason)) throw new UserError(reason_msg('Give a rejection reason.'));
            if($approve){
                $dp=q_one("SELECT * FROM downpayments WHERE downpayment_id=? FOR UPDATE",[$c['downpayment_id']]); if(!$dp||$dp['status']!=='Recorded') throw new UserError('The downpayment is no longer in a correctable state.');
                q("UPDATE downpayment_corrections SET status='Approved',decided_by=?,decided_at=NOW(),decision_reason=? WHERE correction_id=?",[$adminId,trim($reason)?:null,$id]);
                if($c['action']==='Move') q("UPDATE downpayments SET job_id=? WHERE downpayment_id=?",[$c['new_job_id'],$dp['downpayment_id']]);
                else q("UPDATE downpayments SET status='Refunded' WHERE downpayment_id=?",[$dp['downpayment_id']]);
            }else q("UPDATE downpayment_corrections SET status='Rejected',decided_by=?,decided_at=NOW(),decision_reason=? WHERE correction_id=?",[$adminId,trim($reason),$id]);
            audit($approve?'DOWNPAYMENT_CORRECTION_APPROVE':'DOWNPAYMENT_CORRECTION_REJECT','downpayment_corrections',$id);
        });
    }

    public static function settings(): array
    {
        return q_all("SELECT setting_key,setting_value,updated_at FROM settings ORDER BY setting_key");
    }

    public static function updateSetting(string $key,string $value,int $uid): void
    {
        $allowed=['vat_rate','discount_limit_pct','sales_return_days','release_timeout_hours','unbilled_alert_days','or_job_basis'];
        if(!in_array($key,$allowed,true)) throw new UserError('That setting cannot be changed here.');
        if($key==='or_job_basis'&&!in_array($value,['balance_collected','job_total'],true)) throw new UserError('Choose a valid OR value basis.');
        if($key!=='or_job_basis' && (!is_numeric($value) || (float)$value<0)) throw new UserError('Enter a valid non-negative number.');
        q("INSERT INTO settings(setting_key,setting_value,updated_by,updated_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by),updated_at=NOW()",[$key,trim($value),$uid]);
        audit('SETTING_UPDATE','settings',null,null,[$key=>$value]);
    }

    /** Reports each role may open: [key => title]. */
    public static function reportTypes(string $role): array
    {
        $catalog=['stock'=>'Stock Status / Low Stock','movement'=>'Stock Movement','item_history'=>'Item History','physical_count'=>'Physical Count Results','accountability'=>'Checked-Out / Overdue Items','technician'=>'Technician Accountability','incidents'=>'Incident & Loss','sales'=>'Sales Transactions','daily_sales'=>'Daily Sales Summary','cash_drawer'=>'Cash Drawer Report','jobs'=>'Job Register / Status','materials'=>'Materials per Job','job_billing'=>'Job Billing Report','downpayments'=>'Downpayments Held','voids'=>'Voids','returns'=>'Sales Returns','audit'=>'Audit Extract','deliveries'=>'Incoming Deliveries'];
        $roleTypes=[
         'Executive Officer'=>array_keys($catalog),
         'Purchasing Officer'=>['stock','movement','item_history','deliveries'],
         'Logistics'=>['stock','movement','item_history','accountability','technician','jobs','materials'],
         'Sales'=>['sales','cash_drawer','job_billing','voids','returns'],
         'Accounting'=>['sales','daily_sales','cash_drawer','jobs','job_billing','downpayments','voids','returns'],
        ];
        return array_intersect_key($catalog,array_flip($roleTypes[$role]??[]));
    }

    /** Report rows with the same row-level limits as the screen (Sales Staff see only their own). */
    /** Reports that show the current state (as of now); From/To dates do not apply to them. */
    public const SNAPSHOT_REPORTS = ['stock', 'accountability', 'technician', 'downpayments'];

    public static function isSnapshot(string $type): bool { return in_array($type, self::SNAPSHOT_REPORTS, true); }

    /** Message when the From/To dates can't be used, or null when they are fine (or don't apply). */
    public static function periodProblem(string $type, string $from, string $to): ?string
    {
        if (self::isSnapshot($type)) return null;
        if ($from > $to) return 'The From date (' . fmt_d($from) . ') is after the To date (' . fmt_d($to) . '). Pick a From date on or before the To date.';
        return null;
    }

    public static function reportRows(string $type,string $from,string $to): array
    {
        if ($p = self::periodProblem($type, $from, $to)) throw new UserError($p);
        $rows=self::report($type,$from,$to);
        if(current_user()['role']==='Sales') {
            $rows=array_values(array_filter($rows,static function($r){ return (int)($r['recorded_by']??$r['sales_staff_id']??$r['requested_by']??-1)===uid(); }));
        }
        return $rows;
    }

    public static function report(string $type,string $from,string $to): array
    {
        switch($type){
            case 'stock': return q_all("SELECT * FROM v_item_stock s ORDER BY s.stock_status,s.name");
            case 'movement': return q_all("SELECT sm.*,i.item_code,i.name,u.full_name FROM stock_movements sm JOIN items i ON i.item_id=sm.item_id JOIN users u ON u.user_id=sm.created_by WHERE DATE(sm.created_at) BETWEEN ? AND ? ORDER BY sm.movement_id DESC",[$from,$to]);
            case 'item_history': return q_all("SELECT sm.movement_id,sm.created_at,sm.movement_type,sm.qty_change,sm.source_table,sm.source_id,i.item_code,i.name AS item_name,u.full_name AS recorded_by FROM stock_movements sm JOIN items i ON i.item_id=sm.item_id JOIN users u ON u.user_id=sm.created_by WHERE DATE(sm.created_at) BETWEEN ? AND ? ORDER BY sm.movement_id DESC",[$from,$to]);
            case 'accountability': return q_all("SELECT * FROM v_tool_holdings ORDER BY is_overdue DESC,technician,job_ref_no");
            case 'technician': return q_all("SELECT v.assignment_id,v.item_code,v.item_name,c.name AS category,v.technician,v.job_ref_no,v.qty_held,v.expected_return_date,v.status,v.is_overdue,COALESCE((SELECT COUNT(*) FROM incident_reports ir WHERE ir.employee_id=v.technician_id AND ir.status <> 'Resolved'),0) AS open_incidents,COALESCE((SELECT SUM(ir.charge_amount) FROM incident_reports ir WHERE ir.employee_id=v.technician_id),0) AS incident_charges FROM v_tool_holdings v JOIN items i ON i.item_id=v.item_id LEFT JOIN categories c ON c.category_id=i.category_id ORDER BY technician,is_overdue DESC,item_name");
            case 'incidents': return q_all("SELECT ir.*,i.item_code,i.name AS item_name,u.full_name AS employee,j.job_ref_no FROM incident_reports ir JOIN items i ON i.item_id=ir.item_id LEFT JOIN users u ON u.user_id=ir.employee_id LEFT JOIN jobs j ON j.job_id=ir.job_id WHERE ir.date_discovered BETWEEN ? AND ? ORDER BY ir.incident_id DESC",[$from,$to]);
            case 'physical_count': return q_all("SELECT pc.count_id,pc.scope,pc.status,pc.opened_at,pc.closed_at,i.item_code,i.name AS item_name,pcl.system_qty,pcl.counted_qty,pcl.resolution,pcl.explanation,op.full_name AS opened_by,cp.full_name AS counted_by,cl.full_name AS closed_by FROM physical_counts pc JOIN physical_count_lines pcl ON pcl.count_id=pc.count_id JOIN items i ON i.item_id=pcl.item_id LEFT JOIN users op ON op.user_id=pc.opened_by LEFT JOIN users cp ON cp.user_id=pc.counted_by LEFT JOIN users cl ON cl.user_id=pc.closed_by WHERE DATE(pc.opened_at) BETWEEN ? AND ? ORDER BY pc.count_id DESC,pcl.count_line_id",[$from,$to]);
            case 'sales': return q_all("SELECT s.*,u.full_name,ds.drawer_no,d.business_date FROM sales s JOIN users u ON u.user_id=s.recorded_by JOIN drawer_sessions ds ON ds.session_id=s.session_id JOIN sales_days d ON d.sales_day_id=ds.sales_day_id WHERE d.business_date BETWEEN ? AND ? ORDER BY s.sale_id DESC",[$from,$to]);
            case 'daily_sales': return q_all("SELECT * FROM v_daily_summary WHERE business_date BETWEEN ? AND ? ORDER BY business_date DESC",[$from,$to]);
            case 'cash_drawer': return q_all("SELECT sc.session_id,sc.sales_day_id,sc.sales_staff_id,sd.business_date,sc.drawer_no,u.full_name AS sales_staff,sc.opening_float,sc.cash_received,sc.change_given,sc.cash_refunds,sc.expected_cash,sc.counted_cash,ds.difference AS cash_difference,ds.difference_explanation,ds.opened_at,ds.closed_at FROM v_session_cash sc JOIN drawer_sessions ds ON ds.session_id=sc.session_id JOIN users u ON u.user_id=sc.sales_staff_id JOIN sales_days sd ON sd.sales_day_id=sc.sales_day_id WHERE sd.business_date BETWEEN ? AND ? ORDER BY sd.business_date DESC,sc.drawer_no",[$from,$to]);
            case 'jobs': return q_all("SELECT j.*,(SELECT COALESCE(SUM(qty_used),0) FROM v_job_materials m WHERE m.job_id=j.job_id) AS material_qty FROM jobs j WHERE j.job_date BETWEEN ? AND ? ORDER BY j.job_id DESC",[$from,$to]);
            case 'materials': return q_all("SELECT * FROM v_job_materials m JOIN jobs j ON j.job_id=m.job_id WHERE j.job_date BETWEEN ? AND ? ORDER BY m.job_ref_no,m.item_name",[$from,$to]);
            case 'job_billing': return q_all("SELECT s.sale_id,s.sale_ref_no,s.or_number,s.recorded_by,u.full_name AS sales_staff,ds.drawer_no,sd.business_date,s.gross_amount,s.discount_amount,s.taxable_amount,s.vat_rate,s.vat_amount,s.downpayments_applied,s.amount_due,s.status,j.job_ref_no,j.client_name,j.billing_status FROM sales s JOIN jobs j ON j.job_id=s.job_id JOIN users u ON u.user_id=s.recorded_by JOIN drawer_sessions ds ON ds.session_id=s.session_id JOIN sales_days sd ON sd.sales_day_id=ds.sales_day_id WHERE s.sale_type='Job Billing' AND sd.business_date BETWEEN ? AND ? ORDER BY s.sale_id DESC",[$from,$to]);
            case 'downpayments': return q_all("SELECT * FROM v_downpayments_held ORDER BY job_ref_no");
            case 'voids': return q_all("SELECT vr.*,u.full_name AS requester,a.full_name AS approver,s.sale_ref_no,s.or_number FROM void_requests vr JOIN users u ON u.user_id=vr.requested_by LEFT JOIN users a ON a.user_id=vr.decided_by LEFT JOIN sales s ON s.sale_id=vr.sale_id WHERE DATE(vr.requested_at) BETWEEN ? AND ? ORDER BY vr.void_id DESC",[$from,$to]);
            case 'returns': return q_all("SELECT sr.*,s.sale_ref_no,s.or_number,u.full_name AS requester FROM sales_returns sr LEFT JOIN sales s ON s.sale_id=sr.sale_id JOIN users u ON u.user_id=sr.recorded_by WHERE DATE(sr.created_at) BETWEEN ? AND ? ORDER BY sr.sales_return_id DESC",[$from,$to]);
            case 'deliveries': return q_all("SELECT si.stock_in_id,si.date_received,si.dr_po_number,si.qty_received,i.item_code,i.name AS item_name,i.unit_of_measure,si.supplier,si.status,u.full_name AS received_name,en.full_name AS encoded_name,v.full_name AS verified_name,si.verified_at FROM stock_in si JOIN items i ON i.item_id=si.item_id JOIN users u ON u.user_id=si.received_by JOIN users en ON en.user_id=si.encoded_by LEFT JOIN users v ON v.user_id=si.verified_by WHERE si.date_received BETWEEN ? AND ? ORDER BY si.stock_in_id DESC",[$from,$to]);
            case 'audit': return q_all("SELECT a.*,u.full_name FROM audit_log a LEFT JOIN users u ON u.user_id=a.user_id WHERE DATE(a.created_at) BETWEEN ? AND ? ORDER BY a.log_id DESC LIMIT 500",[$from,$to]);
            default: throw new UserError('Choose a report.');
        }
    }
}
