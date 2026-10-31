<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$rows = member_quotas(true);
$activeCount = count(array_filter($rows, fn($r) => $r['remaining'] > 0));
$totalLeft = array_sum(array_map(fn($r) => max(0, $r['remaining']), $rows));
$lastMeet = db()->query("SELECT MAX(played_at) FROM usages WHERE type='play'")->fetchColumn();

layout_start('Kuota', 'public');
?>
<section class="hero">
  <h1>Sisa kuota member</h1>
  <p class="muted">Paket <?= rupiah(setting('package_price')) ?> = <?= (int) setting('quota_per_pkg') ?>× main Sabtu. Meet terakhir dicatat: <strong><?= tgl($lastMeet) ?></strong>.</p>
</section>

<section class="stats">
  <div class="stat"><span class="k">Member</span><span class="v"><?= count($rows) ?></span></div>
  <div class="stat"><span class="k">Masih punya kuota</span><span class="v"><?= $activeCount ?></span></div>
  <div class="stat"><span class="k">Total jatah tersisa</span><span class="v"><?= $totalLeft ?>×</span></div>
</section>

<div class="toolbar">
  <input type="search" id="q" placeholder="Cari nama…" autocomplete="off">
  <div class="chips" id="chips">
    <button class="chip on" data-f="all">Semua</button>
    <button class="chip" data-f="ok low">Ada kuota</button>
    <button class="chip" data-f="empty neg">Habis</button>
  </div>
</div>

<section class="cards" id="list">
<?php foreach ($rows as $r): ?>
  <details class="card" data-name="<?= e(strtolower($r['name'] . ' ' . $r['alias'])) ?>" data-s="<?= e($r['status'][1]) ?>">
    <summary>
      <div class="card-main">
        <div class="name"><?= e($r['name']) ?></div>
        <div class="meta">Bayar <?= tgl($r['last_paid'], true) ?> · Main <?= tgl($r['last_played'], true) ?></div>
      </div>
      <div class="card-side">
        <div class="left <?= e($r['status'][1]) ?>"><?= $r['remaining'] ?><small> sisa</small></div>
        <?= status_badge($r['status']) ?>
      </div>
    </summary>
    <div class="hist">
      <div class="hist-sum">
        <span><?= (int) $r['packages'] ?> paket</span><span><?= (int) $r['played'] ?>× main</span>
        <?php if ($r['refunded']): ?><span><?= (int) $r['refunded'] ?>× refund/cashback</span><?php endif; ?>
      </div>
      <ul>
      <?php foreach (member_history((int) $r['id']) as $h): ?>
        <li class="h-<?= e($h['kind']) ?>">
          <span class="d"><?= tgl($h['d']) ?></span>
          <span><?php
            if ($h['kind'] === 'pay') echo 'Bayar ' . (int) $h['packages'] . ' paket <b>+' . (int) $h['quota'] . '</b>';
            elseif ($h['kind'] === 'play') echo 'Main <b>−1</b>';
            else echo 'Refund/cashback <b>−1</b>';
          ?></span>
        </li>
      <?php endforeach; ?>
      </ul>
    </div>
  </details>
<?php endforeach; ?>
  <p class="empty-msg muted" id="none" hidden>Tidak ada member yang cocok.</p>
</section>

<script>
(() => {
  const q = document.getElementById('q'), chips = document.querySelectorAll('.chip');
  let f = 'all';
  const apply = () => {
    const t = q.value.trim().toLowerCase(); let n = 0;
    document.querySelectorAll('#list .card').forEach(c => {
      const show = c.dataset.name.includes(t) && (f === 'all' || f.split(' ').includes(c.dataset.s));
      c.hidden = !show; if (show) n++;
    });
    document.getElementById('none').hidden = n > 0;
  };
  q.addEventListener('input', apply);
  chips.forEach(b => b.addEventListener('click', () => {
    chips.forEach(x => x.classList.remove('on')); b.classList.add('on'); f = b.dataset.f; apply();
  }));
})();
</script>
<?php layout_end();
