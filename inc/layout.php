<?php
function layout_start(string $title, string $active = ''): void
{
    $f = flash();
    ?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css?v=1">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><circle cx='16' cy='16' r='13' fill='%23c8e64a'/><path d='M6 9c6 4 14 4 20 0M6 23c6-4 14-4 20 0' stroke='%23163a2c' stroke-width='2' fill='none'/></svg>">
</head>
<body>
<header class="top">
  <div class="wrap top-in">
    <a class="brand" href="index.php"><span class="ball"></span><span><?= e(APP_NAME) ?></span></a>
    <nav>
      <?php if ($active === 'public'): ?>
        <a href="admin.php" class="nav-admin">Admin</a>
      <?php else: ?>
        <a href="index.php">Lihat kuota</a>
        <?php if (is_admin()): ?><a href="admin.php?logout=1">Keluar</a><?php endif; ?>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main class="wrap">
<?php if ($f): ?><div class="flash <?= e($f[1]) ?>"><?= e($f[0]) ?></div><?php endif;
}

function layout_end(): void
{
    ?></main>
<footer class="wrap foot">Kuota dihitung otomatis: total paket × kuota per paket − main − refund/cashback.</footer>
</body>
</html><?php
}

function status_badge(array $status): string
{
    return '<span class="badge ' . e($status[1]) . '">' . e($status[0]) . '</span>';
}

function quota_bar(int $remaining, int $quota): string
{
    $pct = $quota > 0 ? max(0, min(100, round($remaining / $quota * 100))) : 0;
    return '<span class="bar"><span style="width:' . $pct . '%"></span></span>';
}
