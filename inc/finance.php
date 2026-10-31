<?php
// Simple cash-basis finance report: member package payments + manual income - manual expenses.

const INCOME_CATEGORIES = ['Tiket reguler', 'Sponsor', 'Penjualan', 'Lain-lain'];
const EXPENSE_CATEGORIES = ['Sewa lapangan', 'Bola', 'Air minum / konsumsi', 'Biaya platform (Reclub)', 'Transport', 'Hadiah / doorprize', 'Lain-lain'];
const MEMBER_INCOME_LABEL = 'Paket member';

function bulan_nama(string $ym): string
{
    $bln = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return $bln[(int) substr($ym, 5, 2) - 1] . ' ' . substr($ym, 0, 4);
}

/** Years that have any finance data, newest first (always includes the current year). */
function finance_years(): array
{
    $rows = db()->query("
        SELECT DISTINCT substr(paid_at, 1, 4) y FROM payments WHERE paid_at IS NOT NULL
        UNION SELECT DISTINCT substr(tx_date, 1, 4) FROM transactions")->fetchAll(PDO::FETCH_COLUMN);
    $rows[] = date('Y');
    $rows = array_values(array_unique(array_map('strval', $rows)));
    rsort($rows);
    return $rows;
}

/** Net result of everything before $year (carried into the cumulative column). */
function finance_opening(string $year): int
{
    $before = $year . '-01-01';
    $st = db()->prepare("
        SELECT
          (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE paid_at < :a)
        + (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE type = 'income' AND tx_date < :b)
        - (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE type = 'expense' AND tx_date < :c)");
    $st->execute(['a' => $before, 'b' => $before, 'c' => $before]);
    return (int) $st->fetchColumn();
}

/** Monthly rows for one year: member income, other income, expense, net, cumulative. */
function finance_monthly(string $year): array
{
    $months = [];
    $add = function (string $ym, string $col, int $v) use (&$months) {
        if (!isset($months[$ym])) {
            $months[$ym] = ['member' => 0, 'other' => 0, 'expense' => 0];
        }
        $months[$ym][$col] += $v;
    };

    $st = db()->prepare("SELECT substr(paid_at, 1, 7) ym, SUM(amount) s FROM payments WHERE substr(paid_at, 1, 4) = ? GROUP BY ym");
    $st->execute([$year]);
    foreach ($st as $r) $add($r['ym'], 'member', (int) $r['s']);

    $st = db()->prepare("SELECT substr(tx_date, 1, 7) ym, type, SUM(amount) s FROM transactions WHERE substr(tx_date, 1, 4) = ? GROUP BY ym, type");
    $st->execute([$year]);
    foreach ($st as $r) $add($r['ym'], $r['type'] === 'income' ? 'other' : 'expense', (int) $r['s']);

    if (!$months) return [];

    // Fill gaps between the first and last month with data (and up to this month for the current year).
    $first = min(array_keys($months));
    $last = max(array_keys($months));
    if ($year === date('Y') && date('Y-m') > $last) $last = date('Y-m');
    for ($ts = strtotime($first . '-01'); date('Y-m', $ts) <= $last; $ts = strtotime('+1 month', $ts)) {
        $add(date('Y-m', $ts), 'member', 0);
    }
    ksort($months);

    $cum = finance_opening($year);
    $out = [];
    foreach ($months as $ym => $m) {
        $income = $m['member'] + $m['other'];
        $net = $income - $m['expense'];
        $cum += $net;
        $out[] = $m + ['ym' => $ym, 'income' => $income, 'net' => $net, 'cumulative' => $cum];
    }
    return $out;
}

/** Totals per category for a year (or one month when $ym is given). */
function finance_by_category(string $year, ?string $ym = null): array
{
    $len = $ym ? 7 : 4;
    $key = $ym ?: $year;
    $res = ['income' => [], 'expense' => []];

    $st = db()->prepare("SELECT COUNT(*) n, COALESCE(SUM(amount), 0) s FROM payments WHERE substr(paid_at, 1, $len) = ?");
    $st->execute([$key]);
    $m = $st->fetch();
    if ((int) $m['s'] > 0) $res['income'][] = ['category' => MEMBER_INCOME_LABEL, 'n' => (int) $m['n'], 'total' => (int) $m['s']];

    $st = db()->prepare("SELECT type, category, COUNT(*) n, SUM(amount) s FROM transactions WHERE substr(tx_date, 1, $len) = ? GROUP BY type, category ORDER BY s DESC");
    $st->execute([$key]);
    foreach ($st as $r) $res[$r['type']][] = ['category' => $r['category'], 'n' => (int) $r['n'], 'total' => (int) $r['s']];
    return $res;
}

/** Value of member quota that has been paid for but not played yet (service still owed). */
function finance_unplayed_liability(): array
{
    $left = 0;
    foreach (member_quotas() as $r) $left += max(0, $r['remaining']);
    $perPlay = (int) round((int) setting('package_price', '160000') / max(1, (int) setting('quota_per_pkg', '4')));
    return ['plays' => $left, 'per_play' => $perPlay, 'value' => $left * $perPlay];
}

/** Ledger: member payments + manual transactions, newest first. */
function finance_ledger(string $year, ?string $ym = null): array
{
    $len = $ym ? 7 : 4;
    $key = $ym ?: $year;
    $st = db()->prepare("
        SELECT * FROM (
            SELECT 'member' src, p.id, p.paid_at d, 'income' type, :lbl category,
                   m.name || ' - ' || p.packages || ' paket' description, p.amount, p.note
            FROM payments p JOIN members m ON m.id = p.member_id
            WHERE substr(p.paid_at, 1, $len) = :k1
            UNION ALL
            SELECT 'manual' src, id, tx_date d, type, category, description, amount, note
            FROM transactions WHERE substr(tx_date, 1, $len) = :k2
        ) ORDER BY d DESC, src, id DESC");
    $st->execute(['lbl' => MEMBER_INCOME_LABEL, 'k1' => $key, 'k2' => $key]);
    return $st->fetchAll();
}
