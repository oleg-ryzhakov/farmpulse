const API = (typeof window.API_BASE === 'string' ? window.API_BASE.replace(/\/$/, '') : '/api');

let currentFarmId = '1';
// Online if last_seen within this window. Must exceed sidecar poll interval (often 30s) + network jitter.
const ONLINE_THRESHOLD_MS = 120000;
let refreshIntervalMs = parseInt(localStorage.getItem('refreshIntervalMs') || '30000', 10);
let refreshTimer = null;
// Bootstrap tooltip instances; recreated after table re-render
let tooltipInstances = [];

function humanizeInterval(ms) {
    if (ms < 60000) return (ms/1000) + 's';
    if (ms % 3600000 === 0) return (ms/3600000) + 'h';
    return (ms/60000) + 'm';
}

let countdownTimer = null;
let nextRefreshDeadline = null;

/** @type {'all'|'online'|'offline'} */
let farmListFilter = (localStorage.getItem('farmListFilter') === 'online' || localStorage.getItem('farmListFilter') === 'offline')
    ? localStorage.getItem('farmListFilter')
    : 'all';

function startCountdown() {
    if (countdownTimer) clearInterval(countdownTimer);
    nextRefreshDeadline = Date.now() + refreshIntervalMs;
    updateCountdownLabel();
    countdownTimer = setInterval(updateCountdownLabel, 1000);
}

function updateCountdownLabel() {
    const remainingMs = Math.max(0, nextRefreshDeadline - Date.now());
    const seconds = Math.ceil(remainingMs / 1000);
    document.getElementById('refreshCountdownLabel').textContent = (seconds > 0 ? seconds : 0) + 's';
    if (remainingMs <= 0) {
        loadFarms();
        nextRefreshDeadline = Date.now() + refreshIntervalMs;
    }
}

function setRefreshInterval(ms) {
    refreshIntervalMs = ms;
    localStorage.setItem('refreshIntervalMs', String(ms));
    document.getElementById('refreshCountdownLabel').textContent = humanizeInterval(ms);
    if (refreshTimer) clearInterval(refreshTimer);
    refreshTimer = setInterval(loadFarms, refreshIntervalMs);
    startCountdown();
}

function parseLastSeen(s) {
    if (!s) return null;
    const parts = s.split(' ');
    if (parts.length !== 2) return null;
    const [y, m, d] = parts[0].split('-').map(Number);
    const [hh, mm, ss] = parts[1].split(':').map(Number);
    // Server stores last_seen_at as UTC (gmdate); compare with Date.now() consistently.
    return new Date(Date.UTC(y, (m || 1) - 1, d || 1, hh || 0, mm || 0, ss || 0));
}

function formatAgoUtc(s) {
    const dt = parseLastSeen(s);
    if (!dt) return '—';
    let sec = Math.floor((Date.now() - dt.getTime()) / 1000);
    if (sec < 0) sec = 0;
    if (sec < 60) return sec + 's';
    if (sec < 3600) return Math.floor(sec / 60) + 'm';
    if (sec < 86400) return Math.floor(sec / 3600) + 'h';
    return Math.floor(sec / 86400) + 'd';
}

function formatUptimeSec(sec) {
    if (sec == null || sec === '' || Number(sec) < 0) return '—';
    const s = Number(sec);
    const d = Math.floor(s / 86400);
    const h = Math.floor((s % 86400) / 3600);
    const m = Math.floor((s % 3600) / 60);
    if (d > 0) return d + 'd ' + h + 'h';
    if (h > 0) return h + 'h ' + m + 'm';
    return m + 'm';
}

function minerSummaryLine(f) {
    const parts = [];
    if (f.summary_miner) parts.push(f.summary_miner);
    if (f.summary_coin) parts.push(f.summary_coin);
    if (f.summary_algo && !f.summary_coin) parts.push(f.summary_algo);
    return parts.length ? parts.join(' · ') : '—';
}

function applyFarmFilterButtons() {
    const g = document.getElementById('farmFilterGroup');
    if (!g) return;
    g.querySelectorAll('[data-farm-filter]').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-farm-filter') === farmListFilter);
    });
}

function loadFarms() {
    return fetch(API + '/v2/farms/farms.php')
        .then(res => res.json())
        .then(data => {
            const select = document.getElementById('farmSelect');
            select.innerHTML = '';
            const farms = data.farms || [];
            farms.forEach(f => {
                const opt = document.createElement('option');
                opt.value = f.id;
                opt.textContent = '#' + f.id + ' - ' + f.name;
                select.appendChild(opt);
            });
            if (farms.length > 0) {
                currentFarmId = farms[0].id;
                select.value = currentFarmId;
            }
            document.getElementById('farms-count').textContent = String(farms.length);
            const now = Date.now();
            const onlineCount = farms.filter(f => {
                const dt = parseLastSeen(f.last_seen_at);
                if (!dt) return false;
                return (now - dt.getTime()) <= ONLINE_THRESHOLD_MS;
            }).length;
            document.getElementById('workers-count').textContent = String(onlineCount);

            const tableBody = document.getElementById('workers-table-body');
            tableBody.innerHTML = '';
            applyFarmFilterButtons();
            farms.forEach(farm => {
                const dt = parseLastSeen(farm.last_seen_at);
                const isOnline = dt ? ((now - dt.getTime()) <= ONLINE_THRESHOLD_MS) : false;
                if (farmListFilter === 'online' && !isOnline) return;
                if (farmListFilter === 'offline' && isOnline) return;
                const row = document.createElement('tr');
                const gpuDots = (farm.gpu_temps || []).map((t, idx) => {
                    const temp = Number(t);
                    const color = isNaN(temp) ? '#6c757d' : (temp >= 80 ? '#dc3545' : (temp >= 60 ? '#ffc107' : '#198754'));
                    const title = 'GPU ' + idx + ' ' + (isNaN(temp) ? '-' : temp + '\u00B0C');
                    return '<span class="gpu-dot" style="background:' + color + '" data-bs-toggle="tooltip" data-bs-placement="top" title="' + title + '"></span>';
                }).join('');
                const khs = farm.total_khs != null && farm.total_khs !== '' ? Number(farm.total_khs).toFixed(1) : '—';
                const pwr = farm.total_power_w != null && farm.total_power_w !== '' ? Math.round(Number(farm.total_power_w)) : '—';
                const heat = farm.heat_warning === true;
                const seenAgo = formatAgoUtc(farm.last_seen_at);
                const seenTitle = (farm.last_seen_at || '').replace(/"/g, '&quot;');
                row.innerHTML = `
                    <td>${farm.id}</td>
                    <td><a class="farm-link" href="farm.php?id=${encodeURIComponent(farm.id)}">${farm.name}</a></td>
                    <td><span class="badge bg-${isOnline ? 'success' : 'danger'}">${isOnline ? 'online' : 'offline'}</span></td>
                    <td class="text-center">${heat ? '<span class="text-warning" title="GPU ≥80°C">⚠</span>' : ''}</td>
                    <td>${gpuDots || '<span class="text-muted">-</span>'}</td>
                    <td class="text-nowrap">${khs}</td>
                    <td class="text-nowrap">${pwr}</td>
                    <td class="text-nowrap small">${farm.summary_loadavg ?? '—'}</td>
                    <td class="small text-truncate" style="max-width:9rem" title="${minerSummaryLine(farm).replace(/"/g, '&quot;')}">${minerSummaryLine(farm)}</td>
                    <td class="text-nowrap small">${formatUptimeSec(farm.summary_uptime_sec)}</td>
                    <td class="text-nowrap small"><span title="${seenTitle}">${seenAgo}</span></td>
                    <td>
                        <div class="dropdown">
                          <button class="btn btn-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">Choose action</button>
                          <ul class="dropdown-menu dropdown-menu-dark">
                            <li><a class="dropdown-item" href="#" onclick="sendReboot('${farm.id}')">Reboot</a></li>
                            <li><a class="dropdown-item" href="#" onclick="openEdit('${farm.id}','${farm.name.replace(/'/g, "&#39;")}')">Edit</a></li>
                            <li><a class="dropdown-item text-danger" href="#" onclick="deleteFarmById('${farm.id}')">Delete</a></li>
                          </ul>
                        </div>
                    </td>
                `;
                tableBody.appendChild(row);
            });

            if (tooltipInstances.length) {
                tooltipInstances.forEach(inst => { try { inst.dispose && inst.dispose(); } catch(_){} });
                tooltipInstances = [];
            }
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
                try { tooltipInstances.push(new bootstrap.Tooltip(el, {container: 'body'})); } catch(_){}
            });
        });
}

function addFarm() {
    const name = document.getElementById('farmName').value;
    const password = document.getElementById('farmPassword').value;
    if (!name || !password) return;

    fetch(API + '/v2/farms/farms.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name, password })
    })
    .then(async res => {
        if (!res.ok) { throw new Error('HTTP ' + res.status + ': ' + await res.text()); }
        return res.json();
    })
    .then(() => {
        const modal = bootstrap.Modal.getInstance(document.getElementById('addFarmModal'));
        modal.hide();
        document.getElementById('farmName').value = '';
        document.getElementById('farmPassword').value = '';
        loadFarms().then(loadWorkers);
    })
    .catch(err => alert('Failed to add farm: ' + err.message));
}

let selectedCoin = null;
async function loadCoins(initialQuery=''){
    const url = API + '/v2/market/coins.php' + (initialQuery ? ('?q=' + encodeURIComponent(initialQuery) + '&limit=200') : '');
    const res = await fetch(url); const data = await res.json();
    const sel = document.getElementById('fsCoinSel');
    if (!sel) return;
    const current = sel.value;
    sel.innerHTML = '<option value="">Choose coin</option>';
    (data.data||[]).forEach(c=>{ const opt=document.createElement('option'); opt.value=c.symbol; opt.textContent = c.symbol + ' - ' + c.name; opt.dataset.algorithm = c.algorithm||''; sel.appendChild(opt); });
    if (current) sel.value = current;
}

async function onCoinChange(){
    const sel = document.getElementById('fsCoinSel');
    const symbol = sel.value; const algo = sel.options[sel.selectedIndex]?.dataset?.algorithm || '';
    if (!symbol){ selectedCoin=null; return; }
    selectedCoin = { symbol, algorithm: algo };
    document.getElementById('fsWalletSel').disabled = false;
    document.getElementById('fsPoolSel').disabled = false;
    document.getElementById('fsMinerSel').disabled = false;
    await loadWalletsForCoin(symbol);
    try {
      const pools = await fetch(API + '/v2/market/pools.php?coin=' + encodeURIComponent(symbol)).then(r=>r.json());
      const selP = document.getElementById('fsPoolSel'); selP.innerHTML = '<option value="">Choose pool</option>';
      (pools.data||[]).forEach(p=>{ const opt=document.createElement('option'); opt.value=p.url?`${p.url}:${p.port||''}`:''; opt.textContent=p.name || (p.url||''); selP.appendChild(opt); });
    } catch(_){ }
    try {
      const miners = await fetch(API + '/v2/market/miners.php?algorithm=' + encodeURIComponent(algo)).then(r=>r.json());
      const selM = document.getElementById('fsMinerSel'); selM.innerHTML = '<option value="">Choose miner</option>';
      (miners.data||[]).forEach(m=>{ const opt=document.createElement('option'); opt.value=m.name; opt.textContent=`${m.name} ${m.version||''}`.trim(); selM.appendChild(opt); });
    } catch(_){ }
}

async function saveAccountFlight(){
    if (!selectedCoin) { alert('Select coin first'); return; }
    const payload = {
        farm_id: currentFarmId,
        miner: document.getElementById('fsMinerSel').value || document.getElementById('fsMiner')?.value?.trim() || '',
        pool: document.getElementById('fsPoolSel').value || document.getElementById('fsPool')?.value?.trim() || '',
        wallet: document.getElementById('fsWalletSel').value || document.getElementById('fsWallet')?.value?.trim() || '',
        pass: document.getElementById('fsPass').value,
        coin: (selectedCoin && selectedCoin.symbol) ? selectedCoin.symbol : '',
        apply: false
    };
    const res = await fetch(API + '/v2/farms/flight.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
    if (!res.ok) { alert('Failed: ' + await res.text()); return; }
    alert('Flight sheet created (saved on farm for now)');
}
async function applyFlightToSelected(){
    if (!selectedCoin) { alert('Select coin first'); return; }
    const payload = {
        farm_id: currentFarmId,
        miner: document.getElementById('fsMinerSel').value || document.getElementById('fsMiner')?.value?.trim() || '',
        pool: document.getElementById('fsPoolSel').value || document.getElementById('fsPool')?.value?.trim() || '',
        wallet: document.getElementById('fsWalletSel').value || document.getElementById('fsWallet')?.value?.trim() || '',
        pass: document.getElementById('fsPass').value,
        coin: (selectedCoin && selectedCoin.symbol) ? selectedCoin.symbol : '',
        apply: true
    };
    const res = await fetch(API + '/v2/farms/flight.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
    if (!res.ok) { alert('Failed: ' + await res.text()); return; }
    alert('Apply queued to selected farm');
}

async function promptAddWallet(){
    if (!selectedCoin) { alert('Select coin first'); return; }
    const address = prompt('Enter wallet address for ' + selectedCoin.symbol + ':');
    if (!address) return;
    const name = prompt('Optional name (label):', address) || address;
    const res = await fetch(API + '/v2/market/wallets.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ coin: selectedCoin.symbol, name, address }) });
    if (!res.ok) { alert('Failed: ' + await res.text()); return; }
    loadWalletsForCoin(selectedCoin.symbol);
}

async function loadWalletsForCoin(symbol){
    try {
      const wallets = await fetch(API + '/v2/market/wallets.php?coin=' + encodeURIComponent(symbol)).then(r=>r.json());
      const selW = document.getElementById('fsWalletSel'); selW.innerHTML = '<option value="">Choose wallet</option>';
      (wallets.data||[]).forEach(w=>{ const opt=document.createElement('option'); opt.value=w.address; opt.textContent=w.name || w.address; selW.appendChild(opt); });
      selW.disabled = false;
    } catch(_){ /* ignore */ }
}

function deleteFarmById(id) {
    if (!confirm('Delete this farm?')) return;
    fetch(`${API}/v2/farms/workers.php?farm_id=${encodeURIComponent(id)}`, { method: 'DELETE' })
    .then(() => loadFarms())
    .catch(err => console.error(err));
}

function sendReboot(id) {
    if (!confirm('Reboot this farm now?')) return;
    fetch(API + '/v2/farms/command.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ farm_id: id, action: 'reboot' })
    })
    .then(async res => {
        if (!res.ok) throw new Error(await res.text());
        return res.json();
    })
    .then(() => alert('Reboot command queued'))
    .catch(err => alert('Failed to queue command: ' + err.message));
}

function openEdit(id, name) {
    document.getElementById('editFarmId').value = id;
    document.getElementById('editFarmTitle').textContent = `Edit ${name}`;
    fetch(`${API}/v2/farms/workers.php?farm_id=${encodeURIComponent(id)}`)
        .then(r => r.json()).then(data => {
            document.getElementById('editFarmPassword').value = data?.farm?.password || '';
            document.getElementById('editFarmName').value = data?.farm?.name || name || '';
            const modal = new bootstrap.Modal(document.getElementById('editFarmModal'));
            modal.show();
        });
}

function applyEdit() {
    const id = document.getElementById('editFarmId').value;
    const password = document.getElementById('editFarmPassword').value;
    const newName = document.getElementById('editFarmName').value;
    if (!password || password.length < 8) { alert('Password must be at least 8 characters'); return; }
    fetch(API + '/v2/farms/command.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ farm_id: id, action: 'update_password', password })
    })
    .then(async res => { if (!res.ok) throw new Error(await res.text()); return res.json(); })
    .then(() => {
        if (newName && newName.trim() !== '') {
            return fetch(API + '/v2/farms/command.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ farm_id: id, action: 'update_name', name: newName.trim() })
            });
        }
    })
    .then(res => { if (res && !res.ok) return res.text().then(t=>{ throw new Error(t);}); })
    .then(() => {
        const modalEl = document.getElementById('editFarmModal');
        bootstrap.Modal.getInstance(modalEl).hide();
        loadFarms();
    })
    .catch(err => alert('Failed to update: ' + err.message));
}

function loadWorkers() {
    fetch(`${API}/v2/farms/workers.php?farm_id=${encodeURIComponent(currentFarmId)}`)
        .then(response => response.json())
        .then(() => {})
        .catch(error => console.error('Error loading workers:', error));
}

function addWorker() {
    const token = prompt('Enter farm token to send heartbeat');
    if (!token) return;
    fetch(`${API}/v2/farms/workers.php?farm_id=${encodeURIComponent(currentFarmId)}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token })
    })
    .then(async response => {
        if (!response.ok) {
            const text = await response.text();
            throw new Error('HTTP ' + response.status + ': ' + text);
        }
        return response.json();
    })
    .then(data => {
        if (data.status === 'OK') { loadWorkers(); }
    })
    .catch(error => {
        console.error('Error adding worker:', error);
        alert('Failed to add worker: ' + error.message);
    });
}

function deleteFarm() {
    if (!confirm('Delete this farm?')) return;
    fetch(`${API}/v2/farms/workers.php?farm_id=${encodeURIComponent(currentFarmId)}`, { method: 'DELETE' })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'OK') {
            loadFarms().then(loadWorkers);
        }
    })
    .catch(error => console.error('Error deleting worker:', error));
}

function applyEwelinkCoolkitForm(data) {
    const rowKey = document.getElementById('ewelinkRowMasterKey');
    const rowKeyOk = document.getElementById('ewelinkRowMasterKeyOk');
    const keySource = document.getElementById('ewelinkMasterKeySource');
    const envWarn = document.getElementById('ewelinkCoolkitEnvWarn');
    const appId = document.getElementById('ewelinkAppId');
    const appSec = document.getElementById('ewelinkAppSecret');
    const masterInput = document.getElementById('ewelinkMasterKey');
    if (!rowKey || !appId) return;

    const keyDone = !!data.encryption_key_configured;
    if (keyDone) {
        rowKey.classList.add('d-none');
        if (masterInput) masterInput.value = '';
        if (rowKeyOk) rowKeyOk.classList.remove('d-none');
        if (keySource) {
            keySource.textContent = data.encryption_key_from_env
                ? '(задан в окружении сервера FARMPULSE_EWELINK_KEY)'
                : '(файл на сервере; через веб не меняется)';
        }
    } else {
        rowKey.classList.remove('d-none');
        if (rowKeyOk) rowKeyOk.classList.add('d-none');
        if (keySource) keySource.textContent = '';
    }

    const fromEnv = !!data.coolkit_from_env;
    if (envWarn) envWarn.classList.toggle('d-none', !fromEnv);
    appId.disabled = fromEnv;
    if (appSec) appSec.disabled = fromEnv;
    appId.value = data.app_id || '';

    if (data.app_secret_configured && appSec) {
        appSec.placeholder = 'уже сохранён — введите новый, чтобы заменить';
        appSec.value = '';
    } else if (appSec) {
        appSec.placeholder = 'APP SECRET из консоли';
    }
    const oauthUrlEl = document.getElementById('ewelinkOAuthCallbackUrl');
    if (oauthUrlEl && data.oauth_callback_url) {
        oauthUrlEl.textContent = data.oauth_callback_url;
    }
}

function loadEwelinkStatus() {
    const el = document.getElementById('ewelinkStatus');
    if (!el) return Promise.resolve();
    return fetch(API + '/v2/integrations/ewelink.php')
        .then(r => r.json())
        .then(data => {
            applyEwelinkCoolkitForm(data);
            if (data.connected) {
                el.innerHTML = 'Аккаунт подключён: <strong>' + (data.account_masked || '—') + '</strong>' +
                    (data.region ? ' · регион ' + data.region : '') +
                    (data.connected_at ? ' · ' + data.connected_at + ' UTC' : '');
                el.classList.remove('text-danger');
            } else {
                el.textContent = 'Аккаунт eWeLink не привязан.';
                el.classList.remove('text-danger');
            }
        })
        .catch(() => {
            el.textContent = 'Не удалось загрузить статус eWeLink (проверьте API и Node.js на сервере).';
            el.classList.add('text-danger');
        });
}

function saveEwelinkCoolkitSettings() {
    const envWarn = document.getElementById('ewelinkCoolkitEnvWarn');
    if (envWarn && !envWarn.classList.contains('d-none')) {
        alert('CoolKit задан в окружении сервера — сохранение из веба отключено.');
        return;
    }
    const enc = document.getElementById('ewelinkMasterKey');
    const rowKey = document.getElementById('ewelinkRowMasterKey');
    const payload = {
        action: 'save_settings',
        app_id: (document.getElementById('ewelinkAppId') && document.getElementById('ewelinkAppId').value) ? document.getElementById('ewelinkAppId').value.trim() : '',
        app_secret: (document.getElementById('ewelinkAppSecret') && document.getElementById('ewelinkAppSecret').value) ? document.getElementById('ewelinkAppSecret').value : ''
    };
    if (rowKey && !rowKey.classList.contains('d-none') && enc && enc.value.trim() !== '') {
        payload.encryption_key = enc.value.trim();
    }
    fetch(API + '/v2/integrations/ewelink.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
        .then(async res => {
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                throw new Error(data.message || data.msg || ('HTTP ' + res.status));
            }
            return data;
        })
        .then(() => {
            if (enc) enc.value = '';
            const appSec = document.getElementById('ewelinkAppSecret');
            if (appSec) appSec.value = '';
            loadEwelinkStatus();
            alert('Настройки CoolKit сохранены.');
        })
        .catch(err => alert('Ошибка: ' + err.message));
}

function startEwelinkOAuth() {
    fetch(API + '/v2/integrations/ewelink.php?action=oauth_start')
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'OK' || !data.url) {
                throw new Error(data.message || 'OAuth');
            }
            window.location.href = data.url;
        })
        .catch(err => alert('Ошибка: ' + err.message));
}

function saveEwelinkAccount() {
    const account = document.getElementById('ewelinkAccount').value.trim();
    const password = document.getElementById('ewelinkPassword').value;
    const area_code = document.getElementById('ewelinkArea').value.trim() || '+7';
    if (!account || !password) {
        alert('Введите email/телефон и пароль');
        return;
    }
    fetch(API + '/v2/integrations/ewelink.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ account, password, area_code })
    })
    .then(async res => {
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            const msg = data.message || data.msg || ('HTTP ' + res.status);
            const hint = data.hint ? ('\n\n' + data.hint) : '';
            throw new Error(msg + hint);
        }
        return data;
    })
    .then(() => {
        document.getElementById('ewelinkPassword').value = '';
        loadEwelinkStatus();
        alert('Аккаунт eWeLink сохранён. Устройства можно будет привязать в настройках фермы.');
    })
    .catch(err => alert('Ошибка: ' + err.message));
}

function removeEwelinkAccount() {
    if (!confirm('Отвязать аккаунт eWeLink?')) return;
    fetch(API + '/v2/integrations/ewelink.php', { method: 'DELETE' })
        .then(r => r.json())
        .then(() => loadEwelinkStatus())
        .catch(err => alert('Ошибка: ' + err.message));
}

document.addEventListener('DOMContentLoaded', () => {
    const ff = document.getElementById('farmFilterGroup');
    if (ff) {
        ff.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-farm-filter]');
            if (!btn) return;
            e.preventDefault();
            farmListFilter = /** @type {'all'|'online'|'offline'} */ (btn.getAttribute('data-farm-filter') || 'all');
            localStorage.setItem('farmListFilter', farmListFilter);
            loadFarms();
        });
    }
    document.getElementById('refreshCountdownLabel').textContent = humanizeInterval(refreshIntervalMs);
    document.querySelectorAll('#refreshDropdown + .dropdown-menu .dropdown-item').forEach(a => {
        a.addEventListener('click', (e) => {
            e.preventDefault();
            const ms = parseInt(a.getAttribute('data-ms'), 10);
            setRefreshInterval(ms);
            loadFarms();
        });
    });
    loadFarms();
    setRefreshInterval(refreshIntervalMs);
    loadEwelinkStatus();
    try {
        const p = new URLSearchParams(window.location.search);
        if (p.get('ewelink_oauth_ok')) {
            alert('Аккаунт eWeLink подключён через OAuth.');
            const u = new URL(window.location.href);
            u.searchParams.delete('ewelink_oauth_ok');
            history.replaceState({}, '', u.pathname + u.search + (window.location.hash || ''));
            loadEwelinkStatus();
        }
        const oauthErr = p.get('ewelink_oauth_err');
        if (oauthErr) {
            alert('OAuth eWeLink: ' + oauthErr);
            const u = new URL(window.location.href);
            u.searchParams.delete('ewelink_oauth_err');
            history.replaceState({}, '', u.pathname + u.search + (window.location.hash || ''));
        }
    } catch (_) { /* ignore */ }
    try { loadCoins(); } catch(_){ }
    const coinSel = document.getElementById('fsCoinSel');
    if (coinSel) coinSel.addEventListener('change', onCoinChange);
    document.getElementById('farmSelect').addEventListener('change', (e) => {
        currentFarmId = e.target.value;
    });
});
