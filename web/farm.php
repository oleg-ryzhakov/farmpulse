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
