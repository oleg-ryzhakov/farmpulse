<?php
header('Content-Type: text/html; charset=utf-8');
require __DIR__ . '/includes/config.php';
$farmId = isset($_GET['id']) ? (string)$_GET['id'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Farm #<?php echo htmlspecialchars($farmId ?: '-'); ?> — Hive OS Management</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="css/farm.css" rel="stylesheet">
</head>
<body>
  <nav class="topbar py-2">
    <div class="container d-flex justify-content-between align-items-center">
      <a class="farm-link" href="index.php">← Back to farms</a>
      <div class="small text-muted">Farm #<span id="titleFarmId"><?php echo htmlspecialchars($farmId ?: '-'); ?></span></div>
    </div>
  </nav>

  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h3 class="mb-0" id="farmNameHeading">Farm</h3>
      <div class="d-flex gap-2">
        <button class="btn btn-secondary btn-sm" onclick="queueReboot()">🔄 Reboot</button>
        <button class="btn btn-outline-light btn-sm" onclick="refreshFarm()">⟳ Refresh</button>
      </div>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-md-3">
        <div class="card p-3">
          <div class="text-muted">Status</div>
          <div class="fs-5" id="farmStatus">-</div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card p-3">
          <div class="text-muted">Last seen</div>
          <div class="fs-5" id="lastSeen">-</div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card p-3">
          <div class="text-muted">GPUs online</div>
          <div class="fs-5" id="gpusOnline">-</div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card p-3">
          <div class="text-muted">Temps</div>
          <div class="fs-5" id="tempsDots">-</div>
        </div>
      </div>
    </div>
    <div class="row g-3 mb-4">
      <div class="col-md-3">
        <div class="card p-3">
          <div class="text-muted">Hashrate (kH/s)</div>
          <div class="fs-5" id="totalKhs">-</div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card p-3">
          <div class="text-muted">Power Σ (W)</div>
          <div class="fs-5" id="totalPower">-</div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card p-3">
          <div class="text-muted">CPU load</div>
          <div class="fs-5" id="cpuLoad">-</div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card p-3">
          <div class="text-muted">RAM / disk</div>
          <div class="fs-6" id="memDisk">-</div>
        </div>
      </div>
    </div>
    <div class="row g-3 mb-4">
      <div class="col-md-3">
        <div class="card p-3">
          <div class="text-muted">System uptime</div>
          <div class="fs-5" id="sysUptime">-</div>
        </div>
      </div>
      <div class="col-md-9">
        <div class="card p-3">
          <div class="text-muted">IP (stats / hello)</div>
          <div class="fs-6" id="netIps">-</div>
        </div>
      </div>
    </div>
    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>GPU (последний stats)</span>
        <span class="small text-muted" id="heatBadge"></span>
      </div>
      <div class="card-body p-0 table-responsive">
        <table class="table table-dark table-sm table-striped mb-0">
          <thead>
            <tr>
              <th>#</th>
              <th>Temp</th>
              <th>Fan</th>
              <th>W</th>
              <th title="По GPU из miner_stats — в разработке">kH/s</th>
            </tr>
          </thead>
          <tbody id="gpuTableBody">
          </tbody>
        </table>
      </div>
    </div>
    <div class="card mb-4">
      <div class="card-header">Rig (hello)</div>
      <div class="card-body small">
        <div class="text-light" id="rigSummaryLines">—</div>
      </div>
    </div>
    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Последний stats (JSON)</span>
        <button class="btn btn-sm btn-outline-light" type="button" data-bs-toggle="collapse" data-bs-target="#lastStatsPre">Показать / скрыть</button>
      </div>
      <div class="collapse" id="lastStatsPre"><pre class="card-body small mb-0 text-wrap" style="max-height:420px;overflow:auto" id="lastStatsJson">—</pre></div>
    </div>

    <div class="alert alert-secondary border-secondary mb-4" style="background: rgba(33,37,41,.5); color: #dee2e6;">
      <strong>eWeLink:</strong> если аккаунт eWeLink уже привязан на главной странице (вкладка «eWeLink»), здесь позже можно будет выбрать устройство для этой фермы (розетка и т.д.). Сейчас привязка устройств к фермам — в следующем шаге разработки.
    </div>

    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <div>Flight sheet</div>
        <button class="btn btn-primary btn-sm" onclick="saveFlight(true)">Apply</button>
      </div>
      <div class="card-body">
        <form id="flightForm" class="row g-3">
          <div class="col-md-3">
            <label class="form-label">Miner</label>
            <input id="fMiner" class="form-control bg-dark text-light" placeholder="teamredminer">
          </div>
          <div class="col-md-3">
            <label class="form-label">Pool URL</label>
            <input id="fPool" class="form-control bg-dark text-light" placeholder="stratum+tcp://POOL:PORT">
          </div>
          <div class="col-md-3">
            <label class="form-label">Wallet</label>
            <input id="fWallet" class="form-control bg-dark text-light" placeholder="WALLET.WORKER">
          </div>
          <div class="col-md-2">
            <label class="form-label">Password</label>
            <input id="fPass" class="form-control bg-dark text-light" value="x">
          </div>
          <div class="col-md-1">
            <label class="form-label">Coin</label>
            <input id="fCoin" class="form-control bg-dark text-light" placeholder="">
          </div>
        </form>
        <div class="mt-3 d-flex gap-2">
          <button class="btn btn-outline-light btn-sm" onclick="saveFlight(false)">Save only</button>
          <button class="btn btn-danger btn-sm" onclick="clearFlight()">Clear</button>
        </div>
      </div>
    </div>
  </div>

  <script>window.API_BASE = <?php echo json_encode(WEB_API_BASE, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;</script>
  <script>window.__FARM_ID__ = <?php echo json_encode($farmId, JSON_UNESCAPED_UNICODE); ?>;</script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="js/farm-app.js"></script>
</body>
</html>
