<?php
/**
 * Cirrus SR22-G6 Turbo Listings
 * Reads cirrus_data.json from GitHub raw URL.
 */

$json_url  = 'https://raw.githubusercontent.com/aroighag-beep/cirrus/main/cirrus_data.json';
$cache_file = __DIR__ . '/cirrus_cache.json';
$cache_ttl  = 1800; // 30 min local cache

// Load from cache or GitHub
$listings = [];
if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
    $listings = json_decode(file_get_contents($cache_file), true) ?: [];
    $source   = 'cache';
} else {
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 15,
            'header'  => "User-Agent: PHP\r\nConnection: close\r\n",
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $raw = @file_get_contents($json_url, false, $ctx);
    if ($raw) {
        $listings = json_decode($raw, true) ?: [];
        file_put_contents($cache_file, $raw);
        $source = 'github';
    } else {
        // Fallback to stale cache
        if (file_exists($cache_file)) {
            $listings = json_decode(file_get_contents($cache_file), true) ?: [];
        }
        $source = 'stale cache';
    }
}

$total = count($listings);
$last_updated = '';
foreach ($listings as $l) {
    if (!empty($l['last_updated'])) { $last_updated = $l['last_updated']; break; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cirrus SR22-G6 Turbo Listings</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: #0f1117; color: #e2e8f0; min-height: 100vh; padding: 24px 16px;
  }
  header {
    max-width: 1400px; margin: 0 auto 20px;
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;
  }
  header h1 { font-size: 1.4rem; font-weight: 700; color: #f8fafc; letter-spacing: -0.02em; }
  header h1 span { color: #60a5fa; }
  .meta { font-size: 0.78rem; color: #64748b; }
  .controls {
    max-width: 1400px; margin: 0 auto 16px;
    display: flex; gap: 12px; flex-wrap: wrap; align-items: center;
  }
  .controls input[type="text"] {
    background: #1e2433; border: 1px solid #2d3748; color: #e2e8f0;
    padding: 8px 14px; border-radius: 8px; font-size: 0.85rem; width: 220px;
    outline: none; transition: border-color .2s;
  }
  .controls input[type="text"]:focus { border-color: #60a5fa; }
  .controls input::placeholder { color: #475569; }
  .filter-group { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
  .filter-label { font-size: 0.75rem; color: #64748b; }
  .filter-btn {
    background: #1e2433; border: 1px solid #2d3748; color: #94a3b8;
    padding: 6px 14px; border-radius: 20px; font-size: 0.78rem; cursor: pointer; transition: all .2s;
  }
  .filter-btn:hover, .filter-btn.active { background: #2563eb; border-color: #2563eb; color: #fff; }
  .reload-btn {
    margin-left: auto; background: #1e2433; border: 1px solid #2d3748; color: #60a5fa;
    padding: 7px 16px; border-radius: 8px; font-size: 0.82rem; cursor: pointer;
    text-decoration: none; transition: all .2s;
  }
  .reload-btn:hover { background: #2d3748; }
  .table-wrap {
    max-width: 1400px; margin: 0 auto; overflow-x: auto;
    border-radius: 12px; border: 1px solid #1e2433;
  }
  table { width: 100%; border-collapse: collapse; font-size: 0.83rem; }
  thead th {
    background: #161b27; color: #94a3b8; padding: 12px 14px; text-align: left;
    font-weight: 600; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;
    cursor: pointer; white-space: nowrap; user-select: none;
    border-bottom: 1px solid #1e2433; position: sticky; top: 0; z-index: 1;
  }
  thead th:hover { color: #e2e8f0; }
  thead th .si { margin-left: 4px; opacity: 0.4; }
  thead th.sorted .si { opacity: 1; color: #60a5fa; }
  tbody tr { border-bottom: 1px solid #1a2030; transition: background .15s; }
  tbody tr:hover { background: #1a2335; }
  tbody tr:last-child { border-bottom: none; }
  td { padding: 11px 14px; vertical-align: middle; color: #cbd5e1; }
  td.year  { font-weight: 700; color: #f1f5f9; font-size: 0.9rem; }
  td.reg   { font-family: monospace; color: #60a5fa; font-weight: 600; }
  td.hours { color: #a3e635; }
  td.price { font-weight: 700; color: #f8fafc; }
  .badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 9px; border-radius: 20px; font-size: 0.72rem; font-weight: 600; white-space: nowrap;
  }
  .b-confirmed { background: #14532d; color: #4ade80; border: 1px solid #166534; }
  .b-likely    { background: #1c3461; color: #93c5fd; border: 1px solid #1d4ed8; }
  .b-no        { background: #1e2433; color: #475569; border: 1px solid #2d3748; }
  .b-wyes      { background: #1a2e1a; color: #86efac; border: 1px solid #166534; }
  .b-wno       { background: #1e2433; color: #475569; border: 1px solid #2d3748; }
  .w-note      { font-size: 0.7rem; color: #64748b; margin-top: 3px; max-width: 180px; line-height: 1.3; }
  .listing-link { color: #60a5fa; text-decoration: none; font-size: 0.75rem; opacity: 0.7; transition: opacity .2s; }
  .listing-link:hover { opacity: 1; }
  tfoot td {
    background: #161b27; color: #64748b; font-size: 0.75rem;
    padding: 10px 14px; border-top: 1px solid #1e2433;
  }
  .hidden { display: none !important; }
  .no-data {
    text-align: center; padding: 60px 20px; color: #475569; font-size: 0.9rem;
  }
  .no-data a { color: #60a5fa; }
</style>
</head>
<body>

<header>
  <h1>Cirrus <span>SR22-G6 Turbo</span> Listings</h1>
  <div class="meta">
    <?= $total ?> listings
    <?php if ($last_updated): ?>
      &nbsp;|&nbsp; Updated <?= date('H:i d/m/Y', strtotime($last_updated)) ?>
    <?php endif; ?>
    &nbsp;|&nbsp; Source: <?= $source ?>
  </div>
</header>

<div class="controls">
  <input type="text" id="search" placeholder="Search reg, location...">
  <div class="filter-group">
    <span class="filter-label">FIKI:</span>
    <button class="filter-btn active" data-filter="fiki" data-value="all">All</button>
    <button class="filter-btn" data-filter="fiki" data-value="Confirmed">Confirmed</button>
    <button class="filter-btn" data-filter="fiki" data-value="Likely">Likely</button>
  </div>
  <div class="filter-group">
    <span class="filter-label">Warranty:</span>
    <button class="filter-btn active" data-filter="warranty" data-value="all">All</button>
    <button class="filter-btn" data-filter="warranty" data-value="Yes">Yes</button>
  </div>
  <a class="reload-btn" href="?fresh=1">Reload Data</a>
</div>

<?php if ($total === 0): ?>
<div class="no-data">
  No listings yet. Trigger the GitHub Action to scrape data:<br><br>
  <a href="https://github.com/aroighag-beep/cirrus/actions" target="_blank">
    github.com/aroighag-beep/cirrus/actions →
  </a>
</div>
<?php else: ?>
<div class="table-wrap">
<table id="tbl">
  <thead>
    <tr>
      <th data-col="year">Year <span class="si">↕</span></th>
      <th data-col="reg">Registration <span class="si">↕</span></th>
      <th data-col="hours">Hours <span class="si">↕</span></th>
      <th data-col="price">Price (USD) <span class="si">↕</span></th>
      <th data-col="location">Location <span class="si">↕</span></th>
      <th data-col="fiki">FIKI <span class="si">↕</span></th>
      <th data-col="warranty">Warranty <span class="si">↕</span></th>
      <th>Link</th>
    </tr>
  </thead>
  <tbody id="tbody">
<?php foreach ($listings as $l):
  $fiki  = $l['fiki'] ?? 'No mention';
  $fcls  = $fiki === 'Confirmed' ? 'b-confirmed' : ($fiki === 'Likely' ? 'b-likely' : 'b-no');
  $ficon = $fiki === 'Confirmed' ? '✓' : ($fiki === 'Likely' ? '~' : '–');
  $wyes  = ($l['warranty'] ?? 'No') === 'Yes';
?>
  <tr data-year="<?= $l['year'] ?? '' ?>"
      data-reg="<?= htmlspecialchars($l['reg'] ?? '') ?>"
      data-hours="<?= $l['hours'] ?? 9999 ?>"
      data-price="<?= $l['price_usd'] ?? 9999999 ?>"
      data-location="<?= htmlspecialchars($l['location'] ?? '') ?>"
      data-fiki="<?= htmlspecialchars($fiki) ?>"
      data-warranty="<?= $wyes ? 'Yes' : 'No' ?>">
    <td class="year"><?= htmlspecialchars($l['year'] ?? '-') ?></td>
    <td class="reg"><?= htmlspecialchars($l['reg'] ?? '-') ?></td>
    <td class="hours"><?= isset($l['hours']) ? number_format((float)$l['hours'], 1) : '-' ?></td>
    <td class="price"><?= htmlspecialchars($l['price_display'] ?? '-') ?></td>
    <td><?= htmlspecialchars($l['location'] ?? '-') ?></td>
    <td><span class="badge <?= $fcls ?>"><?= $ficon ?> <?= htmlspecialchars($fiki) ?></span></td>
    <td>
      <span class="badge <?= $wyes ? 'b-wyes' : 'b-wno' ?>"><?= $wyes ? '✓ Yes' : '– No' ?></span>
      <?php if (!empty($l['warranty_note'])): ?>
      <div class="w-note"><?= htmlspecialchars(ucfirst(strtolower($l['warranty_note']))) ?></div>
      <?php endif; ?>
    </td>
    <td><?php if (!empty($l['url'])): ?>
      <a class="listing-link" href="<?= htmlspecialchars($l['url']) ?>" target="_blank">View →</a>
    <?php else: ?>–<?php endif; ?></td>
  </tr>
<?php endforeach; ?>
  </tbody>
  <tfoot><tr><td colspan="8" id="footer-count"><?= $total ?> listings</td></tr></tfoot>
</table>
</div>
<?php endif; ?>

<?php
// Force fresh fetch from GitHub
if (isset($_GET['fresh']) && file_exists($cache_file)) {
    unlink($cache_file);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}
?>

<script>
// ---- SORT ----
let sortCol = null, sortAsc = true;
document.querySelectorAll('thead th[data-col]').forEach(th => {
  th.addEventListener('click', () => {
    const col = th.dataset.col;
    sortAsc = sortCol === col ? !sortAsc : true;
    sortCol = col;
    document.querySelectorAll('thead th').forEach(t => t.classList.remove('sorted'));
    th.classList.add('sorted');
    th.querySelector('.si').textContent = sortAsc ? '↑' : '↓';
    sortTable(col, sortAsc);
  });
});
function sortTable(col, asc) {
  const tbody = document.getElementById('tbody');
  if (!tbody) return;
  const rows = Array.from(tbody.querySelectorAll('tr'));
  const nums = ['year','hours','price'];
  rows.sort((a,b) => {
    let va = a.dataset[col]||'', vb = b.dataset[col]||'';
    if (nums.includes(col)) { va=parseFloat(va)||0; vb=parseFloat(vb)||0; return asc?va-vb:vb-va; }
    return asc ? va.localeCompare(vb) : vb.localeCompare(va);
  });
  rows.forEach(r => tbody.appendChild(r));
  updateCount();
}

// ---- FILTER ----
const activeFilters = { fiki: 'all', warranty: 'all' };
document.querySelectorAll('.filter-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const type = btn.dataset.filter, val = btn.dataset.value;
    activeFilters[type] = val;
    document.querySelectorAll(`.filter-btn[data-filter="${type}"]`).forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    applyFilters();
  });
});
document.getElementById('search').addEventListener('input', applyFilters);
function applyFilters() {
  const q = document.getElementById('search').value.toLowerCase();
  let visible = 0;
  document.querySelectorAll('#tbody tr').forEach(row => {
    const show = (activeFilters.fiki === 'all' || row.dataset.fiki === activeFilters.fiki)
              && (activeFilters.warranty === 'all' || row.dataset.warranty === activeFilters.warranty)
              && (!q || row.textContent.toLowerCase().includes(q));
    row.classList.toggle('hidden', !show);
    if (show) visible++;
  });
  updateCount(visible);
}
function updateCount(n) {
  const el = document.getElementById('footer-count');
  if (!el) return;
  if (n === undefined) n = document.querySelectorAll('#tbody tr:not(.hidden)').length;
  el.textContent = n + ' listings';
}
</script>
</body>
</html>
