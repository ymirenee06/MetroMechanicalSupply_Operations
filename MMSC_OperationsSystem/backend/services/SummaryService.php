<?php
// Sales summary: totals per day/week/month/quarter/year, best sellers, payment mix, top customers.
// Voided sales are excluded. Amounts are VAT-inclusive totals unless the column says otherwise.

class SummaryService
{
    public const GROUPS = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly'];

    /** Default date range that suits each grouping. */
    public static function defaultRange(string $group): array
    {
        $to = today();
        $from = match ($group) {
            'daily'     => date('Y-m-d', strtotime('-29 days')),
            'weekly'    => date('Y-m-d', strtotime('monday this week -11 weeks')),
            'quarterly' => date('Y-m-d', strtotime(date('Y') - 1 . '-01-01')),
            'yearly'    => date('Y-m-d', strtotime(date('Y') - 4 . '-01-01')),
            default     => date('Y-m-01', strtotime('first day of -5 months')),
        };
        return [$from, $to];
    }

    private static function bucketSql(string $group): string
    {
        return match ($group) {
            'daily'     => "DATE_FORMAT(d.business_date, '%Y-%m-%d')",
            'weekly'    => "DATE_FORMAT(DATE_SUB(d.business_date, INTERVAL WEEKDAY(d.business_date) DAY), '%Y-%m-%d')",
            'quarterly' => "CONCAT(YEAR(d.business_date), '-Q', QUARTER(d.business_date))",
            'yearly'    => "CAST(YEAR(d.business_date) AS CHAR)",
            default     => "DATE_FORMAT(d.business_date, '%Y-%m')",
        };
    }

    public static function label(string $group, string $bucket): string
    {
        return match ($group) {
            'daily'   => date('M j', strtotime($bucket)),
            'weekly'  => 'Wk of ' . date('M j', strtotime($bucket)),
            'monthly' => date('M Y', strtotime($bucket . '-01')),
            default   => $bucket,
        };
    }

    private const BASE = "FROM sales s JOIN drawer_sessions ds ON ds.session_id = s.session_id
                          JOIN sales_days d ON d.sales_day_id = ds.sales_day_id
                          WHERE s.status <> 'Voided' AND d.business_date BETWEEN ? AND ?";

    /** One row per period, including empty periods so the chart has no gaps. */
    public static function trend(string $group, string $from, string $to): array
    {
        $b = self::bucketSql($group);
        $rows = q_all("SELECT $b AS bucket, COUNT(*) AS transactions,
                          SUM(s.sale_type = 'Counter Sale') AS counter_sales, SUM(s.sale_type = 'Job Billing') AS job_billings,
                          SUM(s.gross_amount) AS gross_sales, SUM(s.discount_amount) AS discounts, SUM(s.vat_amount) AS vat,
                          SUM(CASE WHEN s.sale_type = 'Counter Sale' THEN s.taxable_amount + s.vat_amount ELSE 0 END) AS counter_total,
                          SUM(CASE WHEN s.sale_type = 'Job Billing' THEN s.taxable_amount + s.vat_amount ELSE 0 END) AS job_total,
                          SUM(s.taxable_amount + s.vat_amount) AS net_sales
                        " . self::BASE . " GROUP BY bucket ORDER BY bucket", [$from, $to]);
        $byBucket = array_column($rows, null, 'bucket');
        $out = [];
        foreach (self::buckets($group, $from, $to) as $bk) {
            $r = $byBucket[$bk] ?? ['bucket' => $bk, 'transactions' => 0, 'counter_sales' => 0, 'job_billings' => 0, 'gross_sales' => 0, 'discounts' => 0, 'vat' => 0, 'counter_total' => 0, 'job_total' => 0, 'net_sales' => 0];
            $r['period'] = self::label($group, $bk);
            $out[] = $r;
        }
        return $out;
    }

    private static function buckets(string $group, string $from, string $to): array
    {
        $list = [];
        $cur = strtotime($from); $end = strtotime($to);
        if ($group === 'weekly') $cur = strtotime('monday this week', $cur);
        $guard = 0;
        while ($cur <= $end && $guard++ < 2000) {
            $list[] = match ($group) {
                'daily'     => date('Y-m-d', $cur),
                'weekly'    => date('Y-m-d', $cur),
                'quarterly' => date('Y', $cur) . '-Q' . (intdiv((int)date('n', $cur) - 1, 3) + 1),
                'yearly'    => date('Y', $cur),
                default     => date('Y-m', $cur),
            };
            $cur = match ($group) {
                'daily'     => strtotime('+1 day', $cur),
                'weekly'    => strtotime('+1 week', $cur),
                'quarterly' => strtotime('+3 months', strtotime(date('Y-m-01', $cur))),
                'yearly'    => strtotime((date('Y', $cur) + 1) . '-01-01'),
                default     => strtotime('+1 month', strtotime(date('Y-m-01', $cur))),
            };
        }
        return array_values(array_unique($list));
    }

    public static function totals(string $from, string $to): array
    {
        $t = q_one("SELECT COUNT(*) AS transactions, COALESCE(SUM(s.taxable_amount + s.vat_amount),0) AS net_sales,
                       COALESCE(SUM(s.gross_amount),0) AS gross_sales, COALESCE(SUM(s.discount_amount),0) AS discounts, COALESCE(SUM(s.vat_amount),0) AS vat,
                       COALESCE(SUM(CASE WHEN s.sale_type='Counter Sale' THEN s.taxable_amount + s.vat_amount END),0) AS counter_total,
                       COALESCE(SUM(CASE WHEN s.sale_type='Job Billing' THEN s.taxable_amount + s.vat_amount END),0) AS job_total
                     " . self::BASE, [$from, $to]);
        $t['units'] = (float) q_val("SELECT COALESCE(SUM(sl.qty),0) FROM sale_lines sl JOIN sales s ON s.sale_id = sl.sale_id
                                      JOIN drawer_sessions ds ON ds.session_id = s.session_id JOIN sales_days d ON d.sales_day_id = ds.sales_day_id
                                      WHERE s.status <> 'Voided' AND sl.item_id IS NOT NULL AND d.business_date BETWEEN ? AND ?", [$from, $to]);
        $t['refunds'] = (float) q_val("SELECT COALESCE(SUM(sr.refund_amount),0) FROM sales_returns sr WHERE sr.status IN ('Approved','Refunded','Completed') AND DATE(sr.created_at) BETWEEN ? AND ?", [$from, $to]);
        $t['avg_ticket'] = $t['transactions'] ? $t['net_sales'] / $t['transactions'] : 0;
        return $t;
    }

    /** Best sellers. $by = 'qty' or 'revenue'. Counter sales and job materials both count. */
    public static function topItems(string $from, string $to, string $by = 'qty', int $limit = 10): array
    {
        $order = $by === 'revenue' ? 'revenue' : 'units';
        return q_all("SELECT i.item_code, i.name, i.unit_of_measure, i.item_type, SUM(sl.qty) AS units, SUM(sl.line_total) AS revenue,
                        COUNT(DISTINCT s.sale_id) AS transactions
                      FROM sale_lines sl JOIN sales s ON s.sale_id = sl.sale_id JOIN items i ON i.item_id = sl.item_id
                      JOIN drawer_sessions ds ON ds.session_id = s.session_id JOIN sales_days d ON d.sales_day_id = ds.sales_day_id
                      WHERE s.status <> 'Voided' AND d.business_date BETWEEN ? AND ?
                      GROUP BY i.item_id, i.item_code, i.name, i.unit_of_measure, i.item_type ORDER BY $order DESC LIMIT " . (int)$limit, [$from, $to]);
    }

    /** Amount received per payment method (cash includes what was later handed back as change). */
    public static function paymentMix(string $from, string $to): array
    {
        return q_all("SELECT p.method, COUNT(*) AS payments, SUM(p.amount) AS gross_received
                      FROM payments p JOIN sales s ON s.sale_id = p.sale_id
                      JOIN drawer_sessions ds ON ds.session_id = s.session_id JOIN sales_days d ON d.sales_day_id = ds.sales_day_id
                      WHERE s.status <> 'Voided' AND d.business_date BETWEEN ? AND ?
                      GROUP BY p.method ORDER BY gross_received DESC", [$from, $to]);
    }

    public static function changeGiven(string $from, string $to): float
    {
        return (float) q_val("SELECT COALESCE(SUM(s.change_given),0) " . self::BASE, [$from, $to]);
    }

    public static function topCustomers(string $from, string $to, int $limit = 8): array
    {
        return q_all("SELECT s.customer_name, COUNT(*) AS transactions, SUM(s.taxable_amount + s.vat_amount) AS net_sales
                      " . self::BASE . " AND s.customer_name <> 'Walk-in Customer'
                      GROUP BY s.customer_name ORDER BY net_sales DESC LIMIT " . (int)$limit, [$from, $to]);
    }

    public static function byWeekday(string $from, string $to): array
    {
        $rows = q_all("SELECT DAYOFWEEK(d.business_date) AS dow, COUNT(*) AS transactions, SUM(s.taxable_amount + s.vat_amount) AS net_sales
                       " . self::BASE . " GROUP BY dow ORDER BY dow", [$from, $to]);
        $map = array_column($rows, null, 'dow');
        $out = [];
        foreach ([2 => 'Mon', 3 => 'Tue', 4 => 'Wed', 5 => 'Thu', 6 => 'Fri', 7 => 'Sat', 1 => 'Sun'] as $k => $name) {
            $out[] = ['day' => $name, 'transactions' => (int)($map[$k]['transactions'] ?? 0), 'net_sales' => (float)($map[$k]['net_sales'] ?? 0)];
        }
        return $out;
    }

    /** Stacked bar chart (counter + job billing) as inline SVG. Prints cleanly and needs no internet. */
    public static function trendChart(array $trend): string
    {
        $w = 900; $h = 300; $padL = 70; $padB = 46; $padT = 16; $padR = 10;
        $max = 0;
        foreach ($trend as $t) $max = max($max, (float)$t['net_sales']);
        $max = $max > 0 ? self::niceMax($max) : 1000;
        $n = max(1, count($trend));
        $plotW = $w - $padL - $padR; $plotH = $h - $padT - $padB;
        $slot = $plotW / $n; $bw = max(2, min(46, $slot * 0.7));
        $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="Sales per period">';
        for ($g = 0; $g <= 4; $g++) {
            $y = $padT + $plotH - $plotH * $g / 4;
            $svg .= '<line x1="' . $padL . '" x2="' . ($w - $padR) . '" y1="' . $y . '" y2="' . $y . '" stroke="#E3E8EE"/>'
                  . '<text x="' . ($padL - 8) . '" y="' . ($y + 4) . '" font-size="11" text-anchor="end" fill="#4E5D6E">' . e(self::short($max * $g / 4)) . '</text>';
        }
        $every = (int) ceil($n / 14);
        foreach ($trend as $i => $t) {
            $x = $padL + $slot * $i + ($slot - $bw) / 2;
            $c = (float)$t['counter_total']; $j = (float)$t['job_total'];
            $hc = $plotH * $c / $max; $hj = $plotH * $j / $max;
            $yc = $padT + $plotH - $hc; $yj = $yc - $hj;
            $tip = e($t['period'] . ': ' . money($t['net_sales']) . ' (' . $t['transactions'] . ' sales)');
            $svg .= '<g><title>' . $tip . '</title>'
                  . '<rect x="' . round($x, 1) . '" y="' . round($yc, 1) . '" width="' . round($bw, 1) . '" height="' . round($hc, 1) . '" fill="#FD4416"/>'
                  . '<rect x="' . round($x, 1) . '" y="' . round($yj, 1) . '" width="' . round($bw, 1) . '" height="' . round($hj, 1) . '" fill="#1E5AA8"/></g>';
            if ($i % $every === 0) {
                $svg .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . ($h - $padB + 16) . '" font-size="11" text-anchor="middle" fill="#4E5D6E">' . e($t['period']) . '</text>';
            }
        }
        $svg .= '<rect x="' . $padL . '" y="' . ($h - 16) . '" width="12" height="12" fill="#FD4416"/><text x="' . ($padL + 18) . '" y="' . ($h - 6) . '" font-size="12" fill="#071A2E">Counter sales</text>'
              . '<rect x="' . ($padL + 130) . '" y="' . ($h - 16) . '" width="12" height="12" fill="#1E5AA8"/><text x="' . ($padL + 148) . '" y="' . ($h - 6) . '" font-size="12" fill="#071A2E">Job billing</text>';
        return $svg . '</svg>';
    }

    private static function niceMax(float $v): float
    {
        $p = 10 ** floor(log10($v));
        foreach ([1, 2, 4, 6, 8, 10] as $m) if ($m * $p >= $v) return $m * $p;
        return 10 * $p;
    }

    private static function short(float $v): string
    {
        if ($v >= 1e6) return '₱' . rtrim(rtrim(number_format($v / 1e6, 1), '0'), '.') . 'M';
        if ($v >= 1e3) return '₱' . rtrim(rtrim(number_format($v / 1e3, 1), '0'), '.') . 'K';
        return '₱' . number_format($v, 0);
    }
}
