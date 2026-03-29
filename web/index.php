<?php
header('Content-Type: text/html; charset=utf-8');
require __DIR__ . '/includes/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Hive OS Management</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="css/app.css" rel="stylesheet">
</head>
<body>
    <nav class="topbar py-2">
        <div class="container d-flex justify-content-between align-items-center">
            <div class="brand">Hive OS Management</div>
            <div class="d-flex align-items-center gap-3">
                <div class="dropdown">
                    <button class="btn btn-outline-light btn-sm dropdown-toggle d-flex align-items-center gap-2" type="button" id="refreshDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M7 4L5 2M17 4l2-2" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
                            <circle cx="12" cy="13" r="8" stroke="#fff" stroke-width="2"/>
                            <path d="M12 9v4l3 2" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <span id="refreshCountdownLabel">30s</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-dark" id="refreshMenu" aria-labelledby="refreshDropdown">
                        <li><a class="dropdown-item" href="#" data-ms="10000">10 seconds</a></li>
                        <li><a class="dropdown-item" href="#" data-ms="30000">30 seconds</a></li>
                        <li><a class="dropdown-item" href="#" data-ms="60000">1 minute</a></li>
                        <li><a class="dropdown-item" href="#" data-ms="120000">2 minutes</a></li>
                        <li><a class="dropdown-item" href="#" data-ms="300000">5 minutes</a></li>
                        <li><a class="dropdown-item" href="#" data-ms="1800000">30 minutes</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>
    <div class="container py-4">
        <h1 class="text-center mb-4">Hive OS Management</h1>
        <p class="text-center text-muted">Mining Farm Control Panel</p>

        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card stats-card">
                    <div class="stats-number" id="farms-count">0</div>
                    <div class="stats-label">Connected Farms</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card stats-card">
                    <div class="stats-number" id="workers-count">0</div>
                    <div class="stats-label">Active Workers</div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs" id="mainTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="tab-farms" data-bs-toggle="tab" data-bs-target="#pane-farms" type="button" role="tab">Farms</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-flights" data-bs-toggle="tab" data-bs-target="#pane-flights" type="button" role="tab">Flightsheets</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-ewelink" data-bs-toggle="tab" data-bs-target="#pane-ewelink" type="button" role="tab">eWeLink</button>
                    </li>
                </ul>
            </div>
            <div class="card-body tab-content tab-outline">
              <div class="tab-pane fade show active" id="pane-farms" role="tabpanel" aria-labelledby="tab-farms">
                <div class="mb-3 d-flex align-items-center gap-2 flex-wrap">
                    <label for="farmSelect" class="form-label mb-0">Farm:</label>
                    <select id="farmSelect" class="form-select form-select-sm bg-dark text-light" style="width:auto"></select>
                    <div class="btn-group btn-group-sm ms-2" role="group" id="farmFilterGroup" aria-label="Filter">
                        <button type="button" class="btn btn-outline-secondary" data-farm-filter="all">All</button>
                        <button type="button" class="btn btn-outline-secondary" data-farm-filter="online">Online</button>
                        <button type="button" class="btn btn-outline-secondary" data-farm-filter="offline">Offline</button>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#addFarmModal">Add Farm</button>
                </div>
                <div id="workers-list" class="table-responsive">
                    <table class="table table-dark">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Farm Name</th>
                                <th>Status</th>
                                <th title="GPU ≥80°C">⚠</th>
                                <th>GPUs</th>
                                <th>kH/s</th>
                                <th>W</th>
                                <th>Load</th>
                                <th>Miner</th>
                                <th>Uptime</th>
                                <th>Seen</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="workers-table-body">
                        </tbody>
                    </table>
                </div>
              </div>
              <div class="tab-pane fade" id="pane-ewelink" role="tabpanel" aria-labelledby="tab-ewelink">
                <div class="alert alert-secondary border-secondary text-light" style="background: rgba(33,37,41,.6);">
                  <p class="mb-2"><strong>eWeLink</strong> — после привязки аккаунта вы сможете выбирать устройства (например Wi‑Fi розетку) в <strong>настройках каждой фермы</strong> и управлять питанием из сервиса.</p>
                  <p class="mb-0 small text-muted">На сервере нужны Node.js, учётные данные приложения CoolKit (<code>EWELINK_APP_ID</code> / <code>EWELINK_APP_SECRET</code>) и ключ шифрования <code>FARMPULSE_EWELINK_KEY</code> или файл <code>data/ewelink.key</code>.</p>
                </div>
                <div id="ewelinkStatus" class="mb-3 small text-muted">Загрузка статуса…</div>
                <form id="ewelinkForm" class="row g-3" onsubmit="return false;">
                  <div class="col-md-4">
                    <label class="form-label" for="ewelinkAccount">Email или телефон</label>
                    <input type="text" class="form-control bg-dark text-light" id="ewelinkAccount" autocomplete="username" placeholder="user@example.com">
                  </div>
                  <div class="col-md-3">
                    <label class="form-label" for="ewelinkPassword">Пароль eWeLink</label>
                    <input type="password" class="form-control bg-dark text-light" id="ewelinkPassword" autocomplete="current-password">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label" for="ewelinkArea">Код страны</label>
                    <input type="text" class="form-control bg-dark text-light" id="ewelinkArea" value="+7" placeholder="+7">
                  </div>
                  <div class="col-md-3 d-flex align-items-end gap-2 flex-wrap">
                    <button type="button" class="btn btn-primary" onclick="saveEwelinkAccount()">Сохранить аккаунт</button>
                    <button type="button" class="btn btn-outline-danger" onclick="removeEwelinkAccount()">Отвязать</button>
                  </div>
                </form>
              </div>
              <div class="tab-pane fade" id="pane-flights" role="tabpanel" aria-labelledby="tab-flights">
                <div class="row g-2 align-items-end">
                  <div class="col-md-3">
                    <label class="form-label">Coin</label>
                    <select id="fsCoinSel" class="form-select bg-dark text-light">
                      <option value="">Choose coin</option>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Wallet</label>
                    <div class="input-group">
                      <select id="fsWalletSel" class="form-select bg-dark text-light" disabled>
                        <option value="">Choose wallet</option>
                      </select>
                      <button class="btn btn-secondary" type="button" onclick="promptAddWallet()">＋</button>
                    </div>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Pool</label>
                    <select id="fsPoolSel" class="form-select bg-dark text-light" disabled>
                      <option value="">Choose pool</option>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Miner</label>
                    <select id="fsMinerSel" class="form-select bg-dark text-light" disabled>
                      <option value="">Choose miner</option>
                    </select>
                  </div>
                </div>
                <div class="row g-2 align-items-end mt-3">
                  <div class="col-md-4">
                    <label class="form-label">Pool URL (override)</label>
                    <input id="fsPool" class="form-control bg-dark text-light" placeholder="stratum+tcp://POOL:PORT">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Wallet (override)</label>
                    <input id="fsWallet" class="form-control bg-dark text-light" placeholder="WALLET.WORKER">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Password</label>
                    <input id="fsPass" class="form-control bg-dark text-light" value="x">
                  </div>
                  <div class="col-md-2 d-grid">
                    <button class="btn btn-outline-light" onclick="applyFlightToSelected()">Apply to selected farm</button>
                  </div>
                </div>
              </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="addFarmModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Farm</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="addFarmForm">
                        <div class="mb-3">
                            <label for="farmName" class="form-label">Farm Name</label>
                            <input type="text" class="form-control bg-dark text-light" id="farmName" required>
                        </div>
                        <div class="mb-3">
                            <label for="farmPassword" class="form-label">Password</label>
                            <input type="text" class="form-control bg-dark text-light" id="farmPassword" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="addFarm()">Add Farm</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editFarmModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark">
                <div class="modal-header">
                    <h5 class="modal-title" id="editFarmTitle">Edit</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="editFarmForm">
                        <input type="hidden" id="editFarmId">
                        <div class="mb-3">
                            <label for="editFarmName" class="form-label">Farm Name</label>
                            <input type="text" class="form-control bg-dark text-light" id="editFarmName" required>
                        </div>
                        <div class="mb-3">
                            <label for="editFarmPassword" class="form-label">Password</label>
                            <input type="text" class="form-control bg-dark text-light" id="editFarmPassword" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="applyEdit()">Apply</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>window.API_BASE = <?php echo json_encode(WEB_API_BASE, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="js/index-app.js"></script>
</body>
</html>
