<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/finance.php';

$pdo = db();
$p = $_GET['p'] ?? 'dashboard';

// ---------- auth ----------
if (!is_admin()) {
    $err = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
        redirect('admin.php'); // session already expired
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($wait = login_locked($ip)) {
            $err = 'Terlalu banyak percobaan gagal. Coba lagi dalam ' . ceil($wait / 60) . ' menit.';
        } elseif (password_verify($_POST['password'] ?? '', setting('admin_password', ''))) {
            login_cleared($ip);
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            redirect('admin.php');
        } else {
            login_failed($ip);
            $err = 'Password salah.';
            usleep(400000);
        }
    }
    layout_start('Login admin');
    ?>
    <form method="post" class="panel login">
      <h1>Login admin</h1>
      <?= csrf_field() ?>
      <?php if ($err): ?><div class="flash err"><?= e($err) ?></div><?php endif; ?>
      <label>Password<input type="password" name="password" autofocus required></label>
      <button class="btn">Masuk</button>
    </form>
    <?php
    layout_end();
    exit;
}

// ---------- actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = $_POST['action'] ?? '';
    $back = 'admin.php?p=' . urlencode($p);
    $memberExists = function ($id): bool {
        $st = db()->prepare('SELECT 1 FROM members WHERE id = ?');
        $st->execute([(int) $id]);
        return (bool) $st->fetchColumn();
    };

    switch ($a) {
        case 'logout':
            unset($_SESSION['admin']);
            session_regenerate_id(true);
            flash('Anda sudah keluar.');
            redirect('admin.php');

        case 'payment_save':
            $id = (int) ($_POST['id'] ?? 0);
            $date = valid_date($_POST['paid_at'] ?? '');
            $mid = (int) ($_POST['member_id'] ?? 0);
            $pk = max(1, (int) ($_POST['packages'] ?? 1));
            $quota = (int) ($_POST['quota'] ?? 0) ?: $pk * (int) setting('quota_per_pkg', '4');
            $amount = (int) preg_replace('/\D/', '', (string) ($_POST['amount'] ?? '0'));
            if (!$date || !$memberExists($mid)) {
                flash('Tanggal dan member wajib diisi.', 'err');
                redirect($back);
            }
            $vals = [$date, $mid, $pk, $quota, $amount, trim($_POST['method'] ?? '') ?: null, trim($_POST['note'] ?? '') ?: null];
            if ($id) {
                $pdo->prepare('UPDATE payments SET paid_at=?, member_id=?, packages=?, quota=?, amount=?, method=?, note=? WHERE id=?')
                    ->execute([...$vals, $id]);
                flash('Pembayaran diperbarui.');
            } else {
                $pdo->prepare('INSERT INTO payments(paid_at, member_id, packages, quota, amount, method, note) VALUES(?,?,?,?,?,?,?)')
                    ->execute($vals);
                flash('Pembayaran dicatat: +' . $quota . ' kuota.');
            }
            redirect('admin.php?p=payments');

        case 'payment_delete':
            $pdo->prepare('DELETE FROM payments WHERE id = ?')->execute([(int) $_POST['id']]);
            flash('Pembayaran dihapus.');
            redirect($back);

        case 'usage_add':
            $date = valid_date($_POST['played_at'] ?? '');
            $ids = array_map('intval', (array) ($_POST['member_ids'] ?? []));
            $type = ($_POST['type'] ?? 'play') === 'refund' ? 'refund' : 'play';
            if (!$date && $type === 'play') {
                flash('Tanggal meet wajib diisi.', 'err');
                redirect($back);
            }
            if (!$ids) {
                flash('Pilih minimal satu member.', 'err');
                redirect($back);
            }
            $dup = $pdo->prepare("SELECT 1 FROM usages WHERE member_id=? AND played_at=? AND type='play'");
            $ins = $pdo->prepare('INSERT INTO usages(played_at, member_id, type, source, note) VALUES(?,?,?,?,?)');
            $added = 0; $skipped = [];
            $pdo->beginTransaction();
            foreach ($ids as $mid) {
                if (!$memberExists($mid)) continue;
                if ($type === 'play') {
                    $dup->execute([$mid, $date]);
                    if ($dup->fetchColumn()) { $skipped[] = $mid; continue; }
                }
                $ins->execute([$date, $mid, $type, trim($_POST['source'] ?? '') ?: null, trim($_POST['note'] ?? '') ?: null]);
                $added++;
            }
            $pdo->commit();
            $msg = "$added member dipotong kuotanya.";
            if ($skipped) $msg .= ' ' . count($skipped) . ' dilewati karena sudah tercatat main di tanggal itu.';
            flash($msg, $added ? 'ok' : 'err');
            redirect($back);

        case 'usage_delete':
            $pdo->prepare('DELETE FROM usages WHERE id = ?')->execute([(int) $_POST['id']]);
            flash('Penggunaan dihapus (kuota dikembalikan).');
            redirect($back . (isset($_POST['d']) ? '&d=' . urlencode($_POST['d']) : ''));

        case 'member_save':
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                flash('Nama wajib diisi.', 'err');
                redirect($back);
            }
            try {
                if ($id) {
                    $pdo->prepare('UPDATE members SET name=?, alias=?, active=? WHERE id=?')
                        ->execute([$name, trim($_POST['alias'] ?? '') ?: null, isset($_POST['active']) ? 1 : 0, $id]);
                    flash('Member diperbarui.');
                } else {
                    $pdo->prepare('INSERT INTO members(name, alias) VALUES(?, ?)')->execute([$name, trim($_POST['alias'] ?? '') ?: null]);
                    flash("Member $name ditambahkan. Catat pembayarannya di menu Pembayaran.");
                }
            } catch (PDOException $ex) {
                flash('Nama member sudah ada.', 'err');
            }
            redirect('admin.php?p=members');

        case 'member_delete':
            $pdo->prepare('DELETE FROM members WHERE id = ?')->execute([(int) $_POST['id']]);
            flash('Member beserta riwayatnya dihapus.');
            redirect('admin.php?p=members');

        case 'tx_save':
            $id = (int) ($_POST['id'] ?? 0);
            $date = valid_date($_POST['tx_date'] ?? '');
            $type = ($_POST['type'] ?? '') === 'expense' ? 'expense' : 'income';
            $cat = trim($_POST['category'] ?? '');
            $amount = (int) preg_replace('/\D/', '', (string) ($_POST['amount'] ?? '0'));
            if (!$date || $cat === '' || $amount <= 0) {
                flash('Tanggal, kategori, dan nominal (lebih dari 0) wajib diisi.', 'err');
                redirect('admin.php?p=finance' . ($id ? '&edit=' . $id : ''));
            }
            $vals = [$date, $type, $cat, trim($_POST['description'] ?? '') ?: null, $amount, trim($_POST['note'] ?? '') ?: null];
            if ($id) {
                $pdo->prepare('UPDATE transactions SET tx_date=?, type=?, category=?, description=?, amount=?, note=? WHERE id=?')
                    ->execute(array_merge($vals, [$id]));
                flash('Transaksi diperbarui.');
            } else {
                $pdo->prepare('INSERT INTO transactions(tx_date, type, category, description, amount, note) VALUES(?,?,?,?,?,?)')
                    ->execute($vals);
                flash(($type === 'income' ? 'Pendapatan' : 'Pengeluaran') . ' ' . rupiah($amount) . ' dicatat.');
            }
            redirect('admin.php?p=finance&y=' . substr($date, 0, 4));

        case 'tx_delete':
            $pdo->prepare('DELETE FROM transactions WHERE id = ?')->execute([(int) $_POST['id']]);
            flash('Transaksi dihapus.');
            redirect('admin.php?p=finance' . (isset($_POST['y']) ? '&y=' . urlencode($_POST['y']) : ''));

        case 'settings_save':
            $price = (int) preg_replace('/\D/', '', (string) ($_POST['package_price'] ?? ''));
            $qpp = (int) ($_POST['quota_per_pkg'] ?? 0);
            if ($price > 0) set_setting('package_price', (string) $price);
            if ($qpp > 0) set_setting('quota_per_pkg', (string) $qpp);
            flash('Pengaturan disimpan. Berlaku untuk pembayaran baru.');
            redirect($back);

        case 'password_save':
            $old = $_POST['old'] ?? ''; $new = $_POST['new'] ?? '';
            if (!password_verify($old, setting('admin_password', ''))) {
                flash('Password lama salah.', 'err');
            } elseif (strlen($new) < 6 || $new !== ($_POST['new2'] ?? '')) {
                flash('Password baru minimal 6 karakter dan harus sama dengan konfirmasi.', 'err');
            } else {
                set_setting('admin_password', password_hash($new, PASSWORD_DEFAULT));
                flash('Password admin diganti.');
            }
            redirect($back);
    }
    redirect($back);
}

// CSV export: finance ledger for one year
if ($p === 'export_finance') {
    $y = preg_match('/^\d{4}$/', $_GET['y'] ?? '') ? $_GET['y'] : date('Y');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="keuangan-' . $y . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Bulan', 'Pendapatan Member', 'Pendapatan Lain', 'Total Pendapatan', 'Pengeluaran', 'Laba Bersih', 'Akumulasi Laba']);
    foreach (finance_monthly($y) as $m) {
        fputcsv($out, [$m['ym'], $m['member'], $m['other'], $m['income'], $m['expense'], $m['net'], $m['cumulative']]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Tanggal', 'Jenis', 'Kategori', 'Keterangan', 'Nominal', 'Sumber', 'Catatan']);
    foreach (finance_ledger($y) as $t) {
        fputcsv($out, [$t['d'], $t['type'] === 'income' ? 'Pendapatan' : 'Pengeluaran', $t['category'], $t['description'], $t['amount'], $t['src'] === 'member' ? 'Pembayaran member' : 'Manual', $t['note']]);
    }
    exit;
}

// CSV export
if ($p === 'export') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kuota-member-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Nama', 'Alias', 'Paket', 'Total Kuota', 'Terpakai', 'Refund/Cashback', 'Sisa', 'Status', 'Bayar Terakhir', 'Main Terakhir', 'Total Bayar']);
    foreach (member_quotas() as $r) {
        fputcsv($out, [$r['name'], $r['alias'], $r['packages'], $r['quota'], $r['played'], $r['refunded'], $r['remaining'], $r['status'][0], $r['last_paid'], $r['last_played'], $r['amount']]);
    }
    exit;
}

// ---------- views ----------
$members = $pdo->query('SELECT id, name, active FROM members ORDER BY active DESC, name COLLATE NOCASE')->fetchAll();
$quotas = member_quotas();
$byId = array_column($quotas, null, 'id');
$tabs = ['dashboard' => 'Ringkasan', 'usages' => 'Penggunaan', 'payments' => 'Pembayaran', 'finance' => 'Keuangan', 'members' => 'Member', 'settings' => 'Pengaturan'];
if (!isset($tabs[$p])) $p = 'dashboard';

function member_options(array $members, ?int $selected = null): string
{
    $h = '<option value="">— pilih member —</option>';
    foreach ($members as $m) {
        $h .= '<option value="' . $m['id'] . '"' . ($selected === (int) $m['id'] ? ' selected' : '') . '>'
            . e($m['name']) . ($m['active'] ? '' : ' (nonaktif)') . '</option>';
    }
    return $h;
}

function del_button(string $action, int $id, string $confirm, array $extra = []): string
{
    $h = '<form method="post" class="inline" onsubmit="return confirm(\'' . e($confirm) . '\')">' . csrf_field()
        . '<input type="hidden" name="action" value="' . e($action) . '"><input type="hidden" name="id" value="' . $id . '">';
    foreach ($extra as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    return $h . '<button class="btn-x" title="Hapus">Hapus</button></form>';
}

layout_start($tabs[$p], 'admin');
?>
<nav class="tabs" id="tabs">
  <?php foreach ($tabs as $k => $label): ?>
    <a href="admin.php?p=<?= $k ?>" class="<?= $p === $k ? 'on' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
<script>(() => { const a = document.querySelector('#tabs .on'); if (a) document.getElementById('tabs').scrollLeft = a.offsetLeft - 16; })();</script>

<?php if ($p === 'dashboard'):
  $paid = array_sum(array_column($quotas, 'amount'));
  $left = array_sum(array_map(fn($r) => max(0, $r['remaining']), $quotas));
  $neg = array_filter($quotas, fn($r) => $r['remaining'] < 0);
  $perPlay = (int) setting('package_price') / max(1, (int) setting('quota_per_pkg'));
?>
  <section class="stats">
    <div class="stat"><span class="k">Member</span><span class="v"><?= count($quotas) ?></span></div>
    <div class="stat"><span class="k">Jatah tersisa</span><span class="v"><?= $left ?>×</span></div>
    <div class="stat"><span class="k">Total pembayaran</span><span class="v"><?= rupiah($paid) ?></span></div>
    <div class="stat"><span class="k">Nilai jatah belum dimainkan</span><span class="v"><?= rupiah($left * $perPlay) ?></span></div>
  </section>
  <?php if ($neg): ?><div class="flash err">Kuota minus: <?= e(implode(', ', array_column($neg, 'name'))) ?>. Cek pembayaran yang belum tercatat.</div><?php endif; ?>
  <div class="row-between"><h2>Kuota per member</h2><a class="btn ghost" href="admin.php?p=export">Unduh CSV</a></div>
  <div class="table-wrap">
  <table class="tbl">
    <thead><tr><th>Nama</th><th class="n">Paket</th><th class="n">Kuota</th><th class="n">Main</th><th class="n">Refund</th><th class="n">Sisa</th><th>Status</th><th>Bayar terakhir</th><th>Main terakhir</th><th class="n">Total bayar</th></tr></thead>
    <tbody>
    <?php foreach ($quotas as $r): ?>
      <tr class="<?= $r['active'] ? '' : 'dim' ?>">
        <td><?= e($r['name']) ?></td><td class="n"><?= (int) $r['packages'] ?></td><td class="n"><?= (int) $r['quota'] ?></td>
        <td class="n"><?= (int) $r['played'] ?></td><td class="n"><?= (int) $r['refunded'] ?></td>
        <td class="n"><b><?= $r['remaining'] ?></b></td><td><?= status_badge($r['status']) ?></td>
        <td><?= tgl($r['last_paid']) ?></td><td><?= tgl($r['last_played']) ?></td><td class="n"><?= rupiah($r['amount']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

<?php elseif ($p === 'usages'):
  $d = valid_date($_GET['d'] ?? '') ?? recent_saturday();
  $st = $pdo->prepare("SELECT member_id FROM usages WHERE played_at = ? AND type='play'");
  $st->execute([$d]);
  $already = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
?>
  <section class="panel">
    <h2>Catat penggunaan kuota</h2>
    <p class="muted">Centang member yang main memakai kuota. Satu centang = potong 1 kuota.</p>
    <form method="get" class="row" id="dateForm">
      <input type="hidden" name="p" value="usages">
      <label>Tanggal meet<input type="date" name="d" value="<?= e($d) ?>" onchange="this.form.submit()"></label>
    </form>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="usage_add">
      <input type="hidden" name="played_at" value="<?= e($d) ?>">
      <div class="checks">
      <?php foreach ($quotas as $r): if (!$r['active']) continue; $done = in_array((int) $r['id'], $already, true); ?>
        <label class="check <?= $done ? 'done' : '' ?> <?= $r['remaining'] <= 0 ? 'zero' : '' ?>">
          <input type="checkbox" name="member_ids[]" value="<?= $r['id'] ?>" <?= $done ? 'disabled' : '' ?>>
          <span class="cn"><?= e($r['name']) ?></span>
          <span class="cs"><?= $done ? 'sudah dicatat' : 'sisa ' . $r['remaining'] ?></span>
        </label>
      <?php endforeach; ?>
      </div>
      <div class="row">
        <label>Jenis<select name="type"><option value="play">Main (kuota)</option><option value="refund">Refund/Cashback</option></select></label>
        <label>Sumber<select name="source"><option>Reclub</option><option>Manual</option></select></label>
        <label class="grow">Catatan<input name="note" placeholder="opsional, mis. kode event Reclub"></label>
      </div>
      <p class="hint" id="warn" hidden>Ada member terpilih yang kuotanya sudah habis — sisa akan jadi minus.</p>
      <button class="btn">Potong kuota (<span id="cnt">0</span>)</button>
    </form>
  </section>

  <?php
  $list = $pdo->prepare("SELECT u.*, m.name FROM usages u JOIN members m ON m.id = u.member_id WHERE u.played_at = ? ORDER BY m.name COLLATE NOCASE");
  $list->execute([$d]);
  $list = $list->fetchAll();
  ?>
  <h2>Tercatat pada <?= tgl($d) ?> (<?= count($list) ?>)</h2>
  <?php if (!$list): ?><p class="muted">Belum ada.</p><?php else: ?>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Nama</th><th>Jenis</th><th>Sumber</th><th>Catatan</th><th></th></tr></thead>
    <tbody><?php foreach ($list as $u): ?>
      <tr><td><?= e($u['name']) ?></td><td><?= $u['type'] === 'play' ? 'Main (kuota)' : 'Refund/Cashback' ?></td><td><?= e($u['source']) ?></td>
      <td class="note"><?= e($u['note']) ?></td><td class="r"><?= del_button('usage_delete', (int) $u['id'], 'Hapus penggunaan ' . $u['name'] . '? Kuota dikembalikan.', ['d' => $d]) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>

  <h2>Riwayat per meet</h2>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Tanggal</th><th class="n">Main</th><th class="n">Refund</th><th></th></tr></thead>
    <tbody><?php foreach ($pdo->query("SELECT played_at, SUM(type='play') pl, SUM(type='refund') rf FROM usages GROUP BY played_at ORDER BY played_at IS NULL, played_at DESC") as $g): ?>
      <tr><td><?= tgl($g['played_at']) ?></td><td class="n"><?= (int) $g['pl'] ?></td><td class="n"><?= (int) $g['rf'] ?></td>
      <td class="r"><?php if ($g['played_at']): ?><a href="admin.php?p=usages&d=<?= e($g['played_at']) ?>">Lihat</a><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php
  $undated = $pdo->query("SELECT u.*, m.name FROM usages u JOIN members m ON m.id=u.member_id WHERE u.played_at IS NULL")->fetchAll();
  if ($undated): ?>
    <div class="muted undated">Tanpa tanggal:
    <?php foreach ($undated as $u): ?>
      <?= e($u['name']) ?> (<?= $u['type'] === 'refund' ? 'refund/cashback' : 'main' ?>) <?= del_button('usage_delete', (int) $u['id'], 'Hapus baris ini?') ?>
    <?php endforeach; ?></div>
  <?php endif; ?>

  <script>
  (() => {
    const boxes = document.querySelectorAll('.checks input'), cnt = document.getElementById('cnt'), warn = document.getElementById('warn');
    const upd = () => {
      const on = [...boxes].filter(b => b.checked);
      cnt.textContent = on.length;
      warn.hidden = !on.some(b => b.closest('.check').classList.contains('zero'));
    };
    boxes.forEach(b => b.addEventListener('change', upd));
  })();
  </script>

<?php elseif ($p === 'payments'):
  $edit = null;
  if (!empty($_GET['edit'])) {
      $st = $pdo->prepare('SELECT * FROM payments WHERE id = ?');
      $st->execute([(int) $_GET['edit']]);
      $edit = $st->fetch() ?: null;
  }
  $qpp = (int) setting('quota_per_pkg', '4');
  $price = (int) setting('package_price', '160000');
?>
  <section class="panel">
    <h2><?= $edit ? 'Edit pembayaran' : 'Catat pembayaran paket' ?></h2>
    <form method="post" class="grid-form" id="payForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="payment_save">
      <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
      <label>Tanggal bayar<input type="date" name="paid_at" required value="<?= e($edit['paid_at'] ?? date('Y-m-d')) ?>"></label>
      <label>Member<select name="member_id" required><?= member_options($members, isset($edit['member_id']) ? (int) $edit['member_id'] : null) ?></select></label>
      <label>Jumlah paket<input type="number" name="packages" min="1" value="<?= (int) ($edit['packages'] ?? 1) ?>" id="pk"></label>
      <label>Kuota didapat<input type="number" name="quota" min="1" value="<?= (int) ($edit['quota'] ?? $qpp) ?>" id="qt"></label>
      <label>Nominal (Rp)<input name="amount" inputmode="numeric" value="<?= (int) ($edit['amount'] ?? $price) ?>" id="am"></label>
      <label>Metode<select name="method">
        <?php foreach (['', 'Transfer', 'Cash', 'QRIS', 'Lainnya'] as $mt): ?>
          <option value="<?= e($mt) ?>" <?= ($edit['method'] ?? '') === $mt ? 'selected' : '' ?>><?= $mt === '' ? '—' : e($mt) ?></option>
        <?php endforeach; ?>
      </select></label>
      <label class="span2">Catatan<input name="note" value="<?= e($edit['note'] ?? '') ?>"></label>
      <div class="span2 row">
        <button class="btn"><?= $edit ? 'Simpan perubahan' : 'Simpan pembayaran' ?></button>
        <?php if ($edit): ?><a class="btn ghost" href="admin.php?p=payments">Batal</a><?php endif; ?>
      </div>
    </form>
  </section>
  <script>
  (() => {
    const pk = document.getElementById('pk'), qt = document.getElementById('qt'), am = document.getElementById('am');
    pk.addEventListener('input', () => { const n = Math.max(1, +pk.value || 1); qt.value = n * <?= $qpp ?>; am.value = n * <?= $price ?>; });
  })();
  </script>

  <h2>Riwayat pembayaran</h2>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Tanggal</th><th>Nama</th><th class="n">Paket</th><th class="n">Kuota</th><th class="n">Nominal</th><th>Metode</th><th>Catatan</th><th></th></tr></thead>
    <tbody><?php foreach ($pdo->query('SELECT p.*, m.name FROM payments p JOIN members m ON m.id = p.member_id ORDER BY p.paid_at DESC, p.id DESC') as $r): ?>
      <tr><td><?= tgl($r['paid_at']) ?></td><td><?= e($r['name']) ?></td><td class="n"><?= (int) $r['packages'] ?></td><td class="n">+<?= (int) $r['quota'] ?></td>
      <td class="n"><?= rupiah($r['amount']) ?></td><td><?= e($r['method']) ?></td><td class="note"><?= e($r['note']) ?></td>
      <td class="r nowrap"><a href="admin.php?p=payments&edit=<?= $r['id'] ?>">Edit</a> <?= del_button('payment_delete', (int) $r['id'], 'Hapus pembayaran ' . $r['name'] . ' ' . tgl($r['paid_at']) . '?') ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>

<?php elseif ($p === 'members'):
  $edit = null;
  if (!empty($_GET['edit'])) {
      $st = $pdo->prepare('SELECT * FROM members WHERE id = ?');
      $st->execute([(int) $_GET['edit']]);
      $edit = $st->fetch() ?: null;
  }
?>
  <section class="panel">
    <h2><?= $edit ? 'Edit member' : 'Tambah member' ?></h2>
    <form method="post" class="grid-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="member_save">
      <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
      <label>Nama<input name="name" required value="<?= e($edit['name'] ?? '') ?>"></label>
      <label>Alias Reclub (pisah koma)<input name="alias" value="<?= e($edit['alias'] ?? '') ?>"></label>
      <?php if ($edit): ?><label class="cb"><input type="checkbox" name="active" <?= $edit['active'] ? 'checked' : '' ?>> Aktif (tampil di halaman publik & daftar penggunaan)</label><?php endif; ?>
      <div class="span2 row">
        <button class="btn"><?= $edit ? 'Simpan' : 'Tambah member' ?></button>
        <?php if ($edit): ?><a class="btn ghost" href="admin.php?p=members">Batal</a><?php endif; ?>
      </div>
    </form>
  </section>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Nama</th><th>Alias</th><th class="n">Sisa</th><th>Status</th><th></th></tr></thead>
    <tbody><?php foreach ($members as $m): $q = $byId[$m['id']]; ?>
      <tr class="<?= $m['active'] ? '' : 'dim' ?>"><td><?= e($m['name']) ?></td><td><?= e($q['alias']) ?></td><td class="n"><?= $q['remaining'] ?></td>
      <td><?= $m['active'] ? status_badge($q['status']) : '<span class="badge">Nonaktif</span>' ?></td>
      <td class="r nowrap"><a href="admin.php?p=members&edit=<?= $m['id'] ?>">Edit</a> <?= del_button('member_delete', (int) $m['id'], 'Hapus ' . $m['name'] . ' beserta SEMUA riwayat pembayaran & penggunaannya? Untuk menyembunyikan saja, pakai Edit > nonaktifkan.') ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>

<?php elseif ($p === 'finance'):
  $years = finance_years();
  $y = in_array($_GET['y'] ?? '', $years, true) ? $_GET['y'] : $years[0];
  $monthly = finance_monthly($y);
  $tot = ['member' => 0, 'other' => 0, 'income' => 0, 'expense' => 0, 'net' => 0];
  foreach ($monthly as $m) foreach ($tot as $k => $_) $tot[$k] += $m[$k];
  $cumulative = $monthly ? end($monthly)['cumulative'] : finance_opening($y);
  $liab = finance_unplayed_liability();
  $cats = finance_by_category($y);
  $edit = null;
  if (!empty($_GET['edit'])) {
      $st = $pdo->prepare('SELECT * FROM transactions WHERE id = ?');
      $st->execute([(int) $_GET['edit']]);
      $edit = $st->fetch() ?: null;
  }
  $etype = $edit['type'] ?? ($_GET['type'] ?? 'expense');
  if ($etype !== 'income') $etype = 'expense';
?>
  <div class="row-between">
    <form method="get" class="row" style="margin:0">
      <input type="hidden" name="p" value="finance">
      <label>Tahun<select name="y" onchange="this.form.submit()">
        <?php foreach ($years as $yy): ?><option <?= $yy === $y ? 'selected' : '' ?>><?= e($yy) ?></option><?php endforeach; ?>
      </select></label>
    </form>
    <a class="btn ghost" href="admin.php?p=export_finance&y=<?= e($y) ?>">Unduh CSV</a>
  </div>

  <section class="stats">
    <div class="stat"><span class="k">Pendapatan <?= e($y) ?></span><span class="v"><?= rupiah($tot['income']) ?></span></div>
    <div class="stat"><span class="k">Pengeluaran <?= e($y) ?></span><span class="v"><?= rupiah($tot['expense']) ?></span></div>
    <div class="stat"><span class="k">Laba bersih <?= e($y) ?></span><span class="v <?= $tot['net'] < 0 ? 'neg-v' : '' ?>"><?= rupiah($tot['net']) ?></span></div>
    <div class="stat"><span class="k">Akumulasi laba (kas)</span><span class="v <?= $cumulative < 0 ? 'neg-v' : '' ?>"><?= rupiah($cumulative) ?></span></div>
  </section>

  <section class="panel">
    <h2><?= $edit ? 'Edit transaksi' : 'Catat pendapatan / pengeluaran' ?></h2>
    <form method="post" class="grid-form" id="txForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="tx_save">
      <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
      <div class="span2 seg" role="radiogroup" aria-label="Jenis">
        <label class="<?= $etype === 'income' ? 'on' : '' ?>"><input type="radio" name="type" value="income" <?= $etype === 'income' ? 'checked' : '' ?>> Pendapatan</label>
        <label class="<?= $etype === 'expense' ? 'on' : '' ?>"><input type="radio" name="type" value="expense" <?= $etype === 'expense' ? 'checked' : '' ?>> Pengeluaran</label>
      </div>
      <label>Tanggal<input type="date" name="tx_date" required value="<?= e($edit['tx_date'] ?? date('Y-m-d')) ?>"></label>
      <label>Kategori<input name="category" id="cat" list="catList" required autocomplete="off" value="<?= e($edit['category'] ?? '') ?>" placeholder="pilih atau ketik baru"></label>
      <datalist id="catList"></datalist>
      <label>Keterangan<input name="description" value="<?= e($edit['description'] ?? '') ?>" placeholder="mis. Sewa 3 lapangan 2 jam"></label>
      <label>Nominal (Rp)<input name="amount" inputmode="numeric" required value="<?= e(isset($edit['amount']) ? (string) $edit['amount'] : '') ?>"></label>
      <label class="span2">Catatan<input name="note" value="<?= e($edit['note'] ?? '') ?>"></label>
      <p class="span2 muted small">Pembayaran paket member tidak perlu dicatat di sini, karena otomatis diambil dari menu Pembayaran.</p>
      <div class="span2 row" style="margin:0">
        <button class="btn"><?= $edit ? 'Simpan perubahan' : 'Simpan' ?></button>
        <?php if ($edit): ?><a class="btn ghost" href="admin.php?p=finance&y=<?= e($y) ?>">Batal</a><?php endif; ?>
      </div>
    </form>
  </section>
  <script>
  (() => {
    const cats = <?= json_encode(['income' => INCOME_CATEGORIES, 'expense' => EXPENSE_CATEGORIES]) ?>;
    const list = document.getElementById('catList'), radios = document.querySelectorAll('#txForm input[name=type]');
    const fill = () => {
      const t = document.querySelector('#txForm input[name=type]:checked').value;
      list.innerHTML = cats[t].map(c => '<option value="' + c.replace(/"/g, '&quot;') + '">').join('');
      radios.forEach(r => r.parentElement.classList.toggle('on', r.checked));
    };
    radios.forEach(r => r.addEventListener('change', fill)); fill();
  })();
  </script>

  <h2>Laporan bulanan <?= e($y) ?></h2>
  <?php if (!$monthly): ?><p class="muted">Belum ada data di tahun ini.</p><?php else: ?>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Bulan</th><th class="n">Paket member</th><th class="n">Pendapatan lain</th><th class="n">Total pendapatan</th><th class="n">Pengeluaran</th><th class="n">Laba bersih</th><th class="n">Akumulasi</th></tr></thead>
    <tbody><?php foreach ($monthly as $m): ?>
      <tr><td><?= e(bulan_nama($m['ym'])) ?></td><td class="n"><?= rupiah($m['member']) ?></td><td class="n"><?= rupiah($m['other']) ?></td>
      <td class="n"><?= rupiah($m['income']) ?></td><td class="n"><?= rupiah($m['expense']) ?></td>
      <td class="n <?= $m['net'] < 0 ? 'neg-v' : '' ?>"><?= rupiah($m['net']) ?></td><td class="n <?= $m['cumulative'] < 0 ? 'neg-v' : '' ?>"><?= rupiah($m['cumulative']) ?></td></tr>
    <?php endforeach; ?></tbody>
    <tfoot><tr><th>Total</th><th class="n"><?= rupiah($tot['member']) ?></th><th class="n"><?= rupiah($tot['other']) ?></th><th class="n"><?= rupiah($tot['income']) ?></th>
      <th class="n"><?= rupiah($tot['expense']) ?></th><th class="n"><?= rupiah($tot['net']) ?></th><th class="n"><?= rupiah($cumulative) ?></th></tr></tfoot>
  </table></div>
  <?php endif; ?>

  <div class="two-col">
    <section>
      <h2>Per kategori <?= e($y) ?></h2>
      <div class="table-wrap"><table class="tbl">
        <tbody>
          <tr class="grp"><th colspan="2">Pendapatan</th></tr>
          <?php if (!$cats['income']): ?><tr><td colspan="2" class="muted">–</td></tr><?php endif; ?>
          <?php foreach ($cats['income'] as $c): ?><tr><td><?= e($c['category']) ?> <span class="muted">(<?= $c['n'] ?>×)</span></td><td class="n"><?= rupiah($c['total']) ?></td></tr><?php endforeach; ?>
          <tr class="grp"><th colspan="2">Pengeluaran</th></tr>
          <?php if (!$cats['expense']): ?><tr><td colspan="2" class="muted">Belum ada pengeluaran tercatat.</td></tr><?php endif; ?>
          <?php foreach ($cats['expense'] as $c): ?><tr><td><?= e($c['category']) ?> <span class="muted">(<?= $c['n'] ?>×)</span></td><td class="n"><?= rupiah($c['total']) ?></td></tr><?php endforeach; ?>
        </tbody>
      </table></div>
    </section>
    <section>
      <h2>Penyesuaian kuota belum dimainkan</h2>
      <div class="table-wrap"><table class="tbl">
        <tbody>
          <tr><td>Sisa jatah main member (semua)</td><td class="n"><?= $liab['plays'] ?>×</td></tr>
          <tr><td>Nilai per main</td><td class="n"><?= rupiah($liab['per_play']) ?></td></tr>
          <tr><td>Kewajiban main belum terpakai</td><td class="n"><?= rupiah($liab['value']) ?></td></tr>
          <tr><td>Akumulasi laba (kas)</td><td class="n"><?= rupiah($cumulative) ?></td></tr>
          <tr class="grp"><th>Laba setelah kewajiban</th><th class="n <?= $cumulative - $liab['value'] < 0 ? 'neg-v' : '' ?>"><?= rupiah($cumulative - $liab['value']) ?></th></tr>
        </tbody>
      </table></div>
      <p class="muted small">Uang paket yang sudah masuk tapi belum dimainkan masih "utang layanan" ke member. Angka ini dihitung dari sisa kuota saat ini.</p>
    </section>
  </div>

  <div class="row-between"><h2>Transaksi <?= e($y) ?></h2>
    <div class="chips" id="ledgerChips">
      <button type="button" class="chip on" data-f="all">Semua</button>
      <button type="button" class="chip" data-f="manual">Manual</button>
      <button type="button" class="chip" data-f="member">Paket member</button>
    </div>
  </div>
  <?php $ledger = finance_ledger($y); if (!$ledger): ?><p class="muted">Belum ada.</p><?php else: ?>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Tanggal</th><th>Kategori</th><th>Keterangan</th><th class="n">Masuk</th><th class="n">Keluar</th><th></th></tr></thead>
    <tbody id="ledger"><?php foreach ($ledger as $t): ?>
      <tr data-src="<?= e($t['src']) ?>"><td class="nowrap"><?= tgl($t['d']) ?></td><td><?= e($t['category']) ?></td>
      <td><?= e($t['description']) ?><?php if ($t['note']): ?><div class="note"><?= e($t['note']) ?></div><?php endif; ?></td>
      <td class="n pos-v"><?= $t['type'] === 'income' ? rupiah($t['amount']) : '' ?></td><td class="n neg-v"><?= $t['type'] === 'expense' ? rupiah($t['amount']) : '' ?></td>
      <td class="r nowrap"><?php if ($t['src'] === 'manual'): ?><a href="admin.php?p=finance&y=<?= e($y) ?>&edit=<?= (int) $t['id'] ?>">Edit</a> <?= del_button('tx_delete', (int) $t['id'], 'Hapus transaksi ini?', ['y' => $y]) ?>
      <?php else: ?><a href="admin.php?p=payments&edit=<?= (int) $t['id'] ?>" class="muted">dari Pembayaran</a><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <script>
  (() => {
    const chips = document.querySelectorAll('#ledgerChips .chip');
    chips.forEach(c => c.addEventListener('click', () => {
      chips.forEach(x => x.classList.toggle('on', x === c));
      document.querySelectorAll('#ledger tr').forEach(tr => { tr.hidden = c.dataset.f !== 'all' && tr.dataset.src !== c.dataset.f; });
    }));
  })();
  </script>
  <?php endif; ?>

<?php elseif ($p === 'settings'): ?>
  <section class="panel">
    <h2>Paket</h2>
    <form method="post" class="grid-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="settings_save">
      <label>Harga 1 paket (Rp)<input name="package_price" inputmode="numeric" value="<?= e(setting('package_price')) ?>"></label>
      <label>Kuota per paket (× main)<input type="number" min="1" name="quota_per_pkg" value="<?= e(setting('quota_per_pkg')) ?>"></label>
      <p class="span2 muted">Hanya berlaku untuk pembayaran baru. Pembayaran lama menyimpan kuotanya sendiri.</p>
      <div class="span2"><button class="btn">Simpan</button></div>
    </form>
  </section>
  <section class="panel">
    <h2>Ganti password admin</h2>
    <?php if (password_verify(DEFAULT_ADMIN_PASSWORD, setting('admin_password', ''))): ?>
      <div class="flash warn">Masih memakai password default. Ganti sebelum website dipakai publik.</div>
    <?php endif; ?>
    <form method="post" class="grid-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="password_save">
      <label class="span2">Password lama<input type="password" name="old" required></label>
      <label>Password baru<input type="password" name="new" minlength="6" required></label>
      <label>Ulangi password baru<input type="password" name="new2" minlength="6" required></label>
      <div class="span2"><button class="btn">Ganti password</button></div>
    </form>
  </section>
<?php endif;

layout_end();
