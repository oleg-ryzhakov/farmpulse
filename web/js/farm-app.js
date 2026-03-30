(function () {
    'use strict';
    const API = (typeof window.API_BASE === 'string' ? window.API_BASE.replace(/\/$/, '') : '/api');

    const farmId = new URLSearchParams(location.search).get('id') || window.__FARM_ID__ || '';
    /** @type {Record<string, unknown>|null} */
    let lastFarmPayload = null;
    /** @type {'on'|'off'|null} */
    let ewelinkSocketState = null;

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
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
        if (f.summary_algo) parts.push(f.summary_algo);
        return parts.length ? parts.join(' · ') : '';
    }

    function gpuDot(temp) {
        const t = Number(temp);
        const color = isNaN(t) ? '#6c757d' : (t >= 80 ? '#dc3545' : (t >= 60 ? '#ffc107' : '#198754'));
        return `<span class="gpu-dot" style="background:${color}"></span>`;
    }

    /** Hive: [0, …] или [gpu0, gpu1, …] */
    function normalizeHiveGpuArray(arr) {
        if (!Array.isArray(arr) || arr.length === 0) return [];
        if (arr.length > 1 && Number(arr[0]) === 0) return arr.slice(1);
        return arr.slice();
    }

    function gpuMiniBar(pct, hot) {
        const w = Math.max(0, Math.min(100, pct));
        const bg = hot ? '#dc3545' : '#0d6efd';
        return `<div class="gpu-mini-bar" aria-hidden="true"><span style="width:${w}%;background:${bg}"></span></div>`;
    }

    /** Первый miner_stats с массивом hs (kH/s по умолчанию). */
    function extractGpuHashratesKhs(st) {
        if (!st || typeof st !== 'object') return [];
        const keys = ['miner_stats', 'miner_stats2', 'miner_stats3'];
        for (let k = 0; k < keys.length; k++) {
            let ms = st[keys[k]];
            if (ms == null) continue;
            if (typeof ms === 'string') {
                try { ms = JSON.parse(ms); } catch (e) { continue; }
            }
            if (!ms || typeof ms !== 'object' || !Array.isArray(ms.hs)) continue;
            const units = String(ms.hs_units || 'khs').toLowerCase();
            const out = [];
            for (let i = 0; i < ms.hs.length; i++) {
                const raw = Number(ms.hs[i]);
                if (Number.isNaN(raw)) {
                    out.push(null);
                    continue;
                }
                let khs = raw;
                if (units === 'h' || units === 'hs') khs = raw / 1000;
                else if (units === 'mhs' || units === 'mh') khs = raw * 1000;
                else if (units === 'ghs' || units === 'gh') khs = raw * 1e6;
                out.push(khs);
            }
            return out;
        }
        return [];
    }

    /** kH/s → подпись как в Hive (1.048 GH и т.д.). */
    function formatHashrateKhs(khs) {
        if (khs == null || Number.isNaN(khs) || khs <= 0) return '—';
        const v = Number(khs);
        if (v >= 1e6) return (v / 1e6).toFixed(3) + ' GH';
        if (v >= 1e3) return (v / 1e3).toFixed(2) + ' MH';
        return Math.round(v) + ' kH';
    }

    /** Имена GPU из hello (rig_info.gpu) — по индексу. */
    function rigGpuNameAt(rigInfo, idx) {
        if (!rigInfo || rigInfo.gpu == null) return '';
        let g = rigInfo.gpu;
        if (typeof g === 'string') {
            try { g = JSON.parse(g); } catch (e) { return g.trim(); }
        }
        if (Array.isArray(g) && g[idx] != null) return String(g[idx]).trim();
        if (typeof g === 'object' && g[String(idx)] != null) return String(g[String(idx)]).trim();
        return '';
    }

    /** Блок как в Hive: GPU n, шина, модель+VRAM+чип, тип памяти·vbios·PL. */
    function buildGpuCellBlock(opts) {
        const idx = opts.idx;
        const bus = (opts.bus && String(opts.bus).trim()) ? String(opts.bus) : '—';
        const name = (opts.name && String(opts.name).trim()) ? String(opts.name) : '';
        const isPlaceholder = /^GPU\s+\d+$/i.test(name);
        const memTot = (opts.memTot && String(opts.memTot).trim()) ? String(opts.memTot) : '';
        const brand = (opts.brand && String(opts.brand).trim()) ? String(opts.brand) : 'nvidia';
        const chip = brand.toUpperCase();
        const memType = (opts.memType && String(opts.memType).trim()) ? String(opts.memType) : '';
        const vbios = (opts.vbios && String(opts.vbios).trim()) ? String(opts.vbios) : '';
        const plParts = [opts.plim_min, opts.plim_def, opts.plim_max].filter(function (x) {
            return x != null && String(x).trim() !== '';
        });
        const plStr = plParts.length ? ('PL ' + plParts.join(', ')) : '';
        const dParts = [memType, vbios, plStr].filter(function (x) {
            return x != null && String(x).trim() !== '';
        });
        const lineDetail = dParts.length ? dParts.join(' · ') : '—';

        const modelName = name && !isPlaceholder ? name : '';
        let lineModel;
        if (modelName) {
            lineModel = '<span class="text-success fw-semibold">' + escapeHtml(modelName) + '</span>'
                + (memTot ? '<span class="text-muted"> ' + escapeHtml(memTot) + '</span>' : '')
                + '<span class="text-muted"> · ' + escapeHtml(chip) + '</span>';
        } else if (memTot) {
            lineModel = '<span class="text-muted">' + escapeHtml(memTot) + ' · ' + escapeHtml(chip) + '</span>';
        } else {
            lineModel = '<span class="text-muted">—</span>';
        }

        return '<div class="gpu-card-stack">'
            + '<div class="gpu-card-idx text-info fw-semibold">' + escapeHtml('GPU ' + idx) + '</div>'
            + '<div class="gpu-card-bus small text-muted">' + escapeHtml(bus) + '</div>'
            + '<div class="gpu-card-line-model small">' + lineModel + '</div>'
            + '<div class="gpu-card-sub small text-muted">' + escapeHtml(lineDetail) + '</div>'
            + '</div>';
    }

    function renderGpuTable(st, farm) {
        const tbody = document.getElementById('gpuTableBody');
        if (!tbody) return;
        const emptyMsg = '<tr><td colspan="7" class="text-muted px-3 py-3">Нет данных GPU. Убедитесь, что риг шлёт stats (sidecar / Hive agent) и на риге доступен nvidia-smi или /run/hive/gpu-stats.json.</td></tr>';
        const ri = farm && farm.rig_info ? farm.rig_info : null;
        const hsKhs = extractGpuHashratesKhs(st);

        const cards = st && Array.isArray(st.gpu_cards) ? st.gpu_cards : [];
        if (cards.length > 0) {
            let html = '';
            cards.forEach((c) => {
                const t = Number(c.temp);
                const hot = !Number.isNaN(t) && t >= 80;
                const fan = Number(c.fan);
                const tBar = gpuMiniBar(Number.isNaN(t) ? 0 : Math.min(100, t), hot);
                const fBar = gpuMiniBar(Number.isNaN(fan) ? 0 : fan, false);
                const idx = c.index != null ? Number(c.index) : 0;
                let name = (c.name && String(c.name).trim()) ? String(c.name) : rigGpuNameAt(ri, idx);
                const memTot = c.mem_total ? String(c.mem_total) : '';
                const brand = (c.brand && String(c.brand)) ? String(c.brand) : 'nvidia';
                const core = (c.core_mhz != null && c.core_mhz !== '') ? String(c.core_mhz) : '—';
                const mem = (c.mem_mhz != null && c.mem_mhz !== '') ? String(c.mem_mhz) : '—';
                const wVal = c.w != null && Number(c.w) > 0 ? Math.round(Number(c.w)) : null;
                const wStr = wVal != null ? wVal + ' W' : '—';
                const tCell = Number.isNaN(t) ? '—' : (t + '°');
                const fanStr = Number.isNaN(fan) ? '—' : (fan + '%');
                const hr = idx < hsKhs.length && hsKhs[idx] != null ? formatHashrateKhs(hsKhs[idx]) : '—';
                const cell = buildGpuCellBlock({
                    idx: idx,
                    bus: c.bus_id,
                    name: name,
                    memTot: memTot,
                    brand: brand,
                    memType: c.mem_type,
                    vbios: c.vbios,
                    plim_min: c.plim_min,
                    plim_def: c.plim_def,
                    plim_max: c.plim_max
                });
                html += `<tr>
<td class="gpu-card-cell">${cell}</td>
<td class="text-nowrap text-light">${escapeHtml(hr)}</td>
<td class="text-nowrap"><span class="${hot ? 'text-danger fw-bold' : ''}">${escapeHtml(tCell)}</span>${tBar}</td>
<td>${escapeHtml(fanStr)}${fBar}</td>
<td>${escapeHtml(wStr)}</td>
<td>${escapeHtml(core)}</td>
<td>${escapeHtml(mem)}</td>
</tr>`;
            });
            tbody.innerHTML = html;
            return;
        }

        if (!st || !Array.isArray(st.temp)) {
            tbody.innerHTML = emptyMsg;
            return;
        }
        const temps = normalizeHiveGpuArray(st.temp);
        const fans = normalizeHiveGpuArray(Array.isArray(st.fan) ? st.fan : []);
        const powers = normalizeHiveGpuArray(Array.isArray(st.power) ? st.power : []);
        const n = Math.max(temps.length, fans.length, powers.length);
        if (n === 0) {
            tbody.innerHTML = emptyMsg;
            return;
        }
        let html = '';
        for (let i = 0; i < n; i++) {
            const t = Number(temps[i]);
            const fan = Number(fans[i]);
            const w = Number(powers[i]);
            const hot = !Number.isNaN(t) && t >= 80;
            const tStr = !Number.isNaN(t) && t > 0 ? (t + '°') : '—';
            const fStr = !Number.isNaN(fan) ? (fan + '%') : '—';
            const wStr = !Number.isNaN(w) && w > 0 ? (Math.round(w) + ' W') : '—';
            const tBar = gpuMiniBar(Number.isNaN(t) || t <= 0 ? 0 : Math.min(100, t), hot);
            const fBar = gpuMiniBar(Number.isNaN(fan) ? 0 : fan, false);
            const rn = rigGpuNameAt(ri, i);
            const fbCell = buildGpuCellBlock({
                idx: i,
                bus: '—',
                name: rn || '',
                memTot: '',
                brand: 'nvidia',
                memType: '',
                vbios: '',
                plim_min: null,
                plim_def: null,
                plim_max: null
            });
            const hr = i < hsKhs.length && hsKhs[i] != null ? formatHashrateKhs(hsKhs[i]) : '—';
            html += `<tr>
<td class="gpu-card-cell">${fbCell}</td>
<td class="text-nowrap text-light">${escapeHtml(hr)}</td>
<td class="text-nowrap"><span class="${hot ? 'text-danger fw-bold' : ''}">${escapeHtml(tStr)}</span>${tBar}</td>
<td>${escapeHtml(fStr)}${fBar}</td>
<td>${escapeHtml(wStr)}</td>
<td>—</td>
<td>—</td>
</tr>`;
        }
        tbody.innerHTML = html;
    }

    async function refreshFarm() {
        const res = await fetch(`${API}/v2/farms/workers.php?farm_id=${encodeURIComponent(farmId)}`);
        const data = await res.json();
        const farm = data.farm || {};
        document.getElementById('titleFarmId').textContent = farm.id || farmId;
        document.getElementById('farmNameHeading').textContent = farm.name || ('Farm #' + farmId);
        document.getElementById('farmStatus').textContent = farm.status || '-';
        document.getElementById('lastSeen').textContent = farm.last_seen_at || '-';

        const temps = (farm.gpu_temps && farm.gpu_temps.length) ? farm.gpu_temps : [];
        const gc = farm.gpu_count != null && farm.gpu_count !== '' ? Number(farm.gpu_count) : temps.length;
        document.getElementById('gpusOnline').textContent = String(gc);
        document.getElementById('tempsDots').innerHTML = temps.map(gpuDot).join('');

        const khs = farm.total_khs;
        document.getElementById('totalKhs').textContent = (khs != null && khs !== '') ? Number(khs).toFixed(2) : '—';
        const pw = farm.total_power_w;
        document.getElementById('totalPower').textContent = (pw != null && pw !== '') ? String(Math.round(Number(pw))) : '—';
        const st = farm.last_stats || {};
        const la = st.cpuavg;
        document.getElementById('cpuLoad').textContent = Array.isArray(la) ? la.join(' / ') : (typeof la === 'string' ? la : '—');
        const mem = st.mem;
        const df = st.df;
        let md = '—';
        if (Array.isArray(mem) && mem.length >= 2) md = 'RAM ' + mem[0] + ' MiB, своб. ' + mem[1] + ' MiB';
        if (df) md += (md === '—' ? '' : ' · ') + 'root ' + df;
        document.getElementById('memDisk').textContent = md;

        const su = document.getElementById('sysUptime');
        if (su) su.textContent = formatUptimeSec(farm.summary_uptime_sec);

        const ipsHello = farm.rig_info && farm.rig_info.ip ? String(farm.rig_info.ip) : '';
        const ipsStats = Array.isArray(farm.summary_net_ips) ? farm.summary_net_ips.join(', ') : '';
        const ni = document.getElementById('netIps');
        if (ni) {
            const parts = [];
            if (ipsStats) parts.push('stats: ' + ipsStats);
            if (ipsHello) parts.push('hello: ' + ipsHello);
            ni.textContent = parts.length ? parts.join(' · ') : '—';
        }

        const hb = document.getElementById('heatBadge');
        if (hb) {
            hb.textContent = farm.heat_warning === true ? '⚠ GPU ≥80°C' : '';
        }

        renderGpuTable(st, farm);

        const ri = farm.rig_info;
        const rigLine = document.getElementById('rigSummaryLines');
        if (rigLine) {
            const bits = [];
            const ms = minerSummaryLine(farm);
            if (ms) bits.push('<strong>Майнинг:</strong> ' + escapeHtml(ms));
            if (ri && typeof ri === 'object') {
                if (ri.gpu_count_nvidia != null || ri.gpu_count_amd != null) {
                    bits.push('<strong>GPU:</strong> NV ' + escapeHtml(String(ri.gpu_count_nvidia ?? '—'))
                        + ' / AMD ' + escapeHtml(String(ri.gpu_count_amd ?? '—'))
                        + ' / Intel ' + escapeHtml(String(ri.gpu_count_intel ?? '—')));
                }
                if (ri.nvidia_version) bits.push('<strong>NVIDIA:</strong> ' + escapeHtml(ri.nvidia_version));
                if (ri.kernel) bits.push('<strong>Kernel:</strong> ' + escapeHtml(ri.kernel));
                if (ri.image_version) bits.push('<strong>Image:</strong> ' + escapeHtml(ri.image_version));
                if (ri.version) bits.push('<strong>Hive:</strong> ' + escapeHtml(ri.version));
            }
            if (bits.length) {
                rigLine.innerHTML = bits.join('<br>');
            } else if (ri && typeof ri === 'object' && Object.keys(ri).length) {
                rigLine.textContent = JSON.stringify(ri);
            } else {
                rigLine.textContent = '—';
            }
        }

        const js = document.getElementById('lastStatsJson');
        try {
            js.textContent = farm.last_stats ? JSON.stringify(farm.last_stats, null, 2) : '—';
        } catch (e) { js.textContent = '—'; }

        const fs = await fetch(`${API}/v2/farms/flight.php?farm_id=${encodeURIComponent(farmId)}`).then(r => r.json());
        if (fs && fs.flightsheet) {
            document.getElementById('fMiner').value = fs.flightsheet.miner || '';
            document.getElementById('fPool').value = fs.flightsheet.pool || '';
            document.getElementById('fWallet').value = fs.flightsheet.wallet || '';
            document.getElementById('fPass').value = fs.flightsheet.pass ?? 'x';
            document.getElementById('fCoin').value = fs.flightsheet.coin || '';
        }

        await loadEwelinkFarmBlock(farm);
        lastFarmPayload = farm;
        updateFarmEwelinkToolbar(farm);
    }

    function updateFarmEwelinkToolbar(farm) {
        const bar = document.getElementById('farmEwelinkBar');
        if (!bar) {
            return;
        }
        const id = farm.ewelink_device_id != null && String(farm.ewelink_device_id).trim() !== ''
            ? String(farm.ewelink_device_id).trim()
            : '';
        if (!id) {
            bar.classList.add('d-none');
            return;
        }
        bar.classList.remove('d-none');
        const label = document.getElementById('farmEwelinkBarLabel');
        const name = (farm.ewelink_device_name && String(farm.ewelink_device_name).trim())
            ? String(farm.ewelink_device_name).trim()
            : id;
        if (label) {
            label.textContent = 'Розетка: ' + name;
        }
        farmEwelinkRefreshStatus();
    }

    function farmEwelinkToolbarSetLoading() {
        const line = document.getElementById('farmEwelinkBarStatusLine');
        const btn = document.getElementById('farmEwelinkBarToggle');
        if (line) {
            line.textContent = 'Запрос статуса…';
            line.classList.remove('ewelink-state-on', 'ewelink-state-off', 'ewelink-state-unknown', 'ewelink-state-offline');
            line.classList.add('ewelink-state-unknown');
        }
        if (btn) {
            btn.disabled = true;
            btn.textContent = '…';
            btn.className = 'btn btn-sm btn-secondary';
        }
    }

    function applyEwelinkToolbarStatus(data, errMsg) {
        const line = document.getElementById('farmEwelinkBarStatusLine');
        const btn = document.getElementById('farmEwelinkBarToggle');
        if (!line || !btn) {
            return;
        }
        line.classList.remove('ewelink-state-on', 'ewelink-state-off', 'ewelink-state-unknown', 'ewelink-state-offline');
        line.removeAttribute('title');
        btn.removeAttribute('title');

        if (errMsg) {
            ewelinkSocketState = null;
            line.textContent = 'Статус: ошибка';
            line.classList.add('ewelink-state-offline');
            line.title = errMsg;
            btn.textContent = 'Повторить';
            btn.className = 'btn btn-sm btn-outline-warning';
            btn.disabled = false;
            return;
        }

        const online = data.online !== false;
        const sw = data.switch === 'on' || data.switch === 'off' ? data.switch : null;
        ewelinkSocketState = sw;

        if (!online) {
            line.textContent = 'Розетка offline в eWeLink';
            line.classList.add('ewelink-state-offline');
            btn.textContent = 'Нет связи';
            btn.className = 'btn btn-sm btn-outline-secondary';
            btn.disabled = true;
            return;
        }

        if (sw === 'on') {
            line.textContent = 'Сейчас: включена';
            line.classList.add('ewelink-state-on');
            btn.textContent = 'Выключить';
            btn.className = 'btn btn-sm btn-danger';
            btn.disabled = false;
            return;
        }
        if (sw === 'off') {
            line.textContent = 'Сейчас: выключена';
            line.classList.add('ewelink-state-off');
            btn.textContent = 'Включить';
            btn.className = 'btn btn-sm btn-success';
            btn.disabled = false;
            return;
        }

        line.textContent = 'Состояние не определено — нажмите ⟳';
        line.classList.add('ewelink-state-unknown');
        btn.textContent = 'Обновить статус';
        btn.className = 'btn btn-sm btn-outline-light';
        btn.disabled = false;
        btn.title = 'Если модель не отдаёт switch в API, статус может остаться неизвестным';
    }

    async function farmEwelinkToggle() {
        if (ewelinkSocketState === 'on') {
            await farmEwelinkSwitch(false);
        } else if (ewelinkSocketState === 'off') {
            await farmEwelinkSwitch(true);
        } else {
            await farmEwelinkRefreshStatus();
        }
    }

    async function farmEwelinkSwitch(on) {
        const farm = lastFarmPayload;
        if (!farm || !farm.ewelink_device_id) {
            return;
        }
        const itemType = Number(farm.ewelink_device_item_type) === 2 ? 2 : 1;
        const tgl = document.getElementById('farmEwelinkBarToggle');
        if (tgl) {
            tgl.disabled = true;
            tgl.textContent = '…';
        }
        const res = await fetch(`${API}/v2/integrations/ewelink.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'device_switch',
                device_id: String(farm.ewelink_device_id),
                item_type: itemType,
                on: !!on
            })
        });
        const data = await res.json().catch(function () { return {}; });
        if (!res.ok) {
            if (tgl) {
                tgl.disabled = false;
            }
            applyEwelinkToolbarStatus({}, String(data.message || res.status));
            alert('eWeLink: ' + (data.message || res.status));
            return;
        }
        farmEwelinkRefreshStatus();
    }

    async function farmEwelinkRefreshStatus() {
        const farm = lastFarmPayload;
        if (!farm || !farm.ewelink_device_id) {
            return;
        }
        farmEwelinkToolbarSetLoading();
        const res = await fetch(`${API}/v2/integrations/ewelink.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'device_status',
                device_id: String(farm.ewelink_device_id)
            })
        });
        const data = await res.json().catch(function () { return {}; });
        if (!res.ok) {
            applyEwelinkToolbarStatus({}, String(data.message || ('HTTP ' + res.status)));
            return;
        }
        applyEwelinkToolbarStatus(data, '');
    }

    function ewelinkFarmClearSelect(sel) {
        while (sel.firstChild) {
            sel.removeChild(sel.firstChild);
        }
        const o0 = document.createElement('option');
        o0.value = '';
        o0.textContent = '— не выбрано —';
        sel.appendChild(o0);
    }

    async function loadEwelinkFarmBlock(farm) {
        const hint = document.getElementById('ewelinkFarmHint');
        const sel = document.getElementById('ewelinkFarmSelect');
        const saveBtn = document.getElementById('ewelinkFarmSaveBtn');
        const refreshBtn = document.getElementById('ewelinkFarmRefreshBtn');
        if (!hint || !sel || !saveBtn) {
            return;
        }
        try {
            const stRes = await fetch(`${API}/v2/integrations/ewelink.php`);
            const st = await stRes.json();
            if (!st.connected) {
                hint.innerHTML = 'Аккаунт eWeLink не привязан. На <a href="index.php" class="link-light">главной</a> откройте вкладку «eWeLink» и выполните OAuth.';
                ewelinkFarmClearSelect(sel);
                sel.disabled = true;
                saveBtn.disabled = true;
                if (refreshBtn) {
                    refreshBtn.disabled = true;
                }
                return;
            }
            if (refreshBtn) {
                refreshBtn.disabled = false;
            }
            await populateEwelinkDeviceSelect(farm);
        } catch (e) {
            hint.textContent = 'Не удалось проверить статус eWeLink.';
            sel.disabled = true;
            saveBtn.disabled = true;
            if (refreshBtn) {
                refreshBtn.disabled = true;
            }
        }
    }

    async function populateEwelinkDeviceSelect(farm) {
        const hint = document.getElementById('ewelinkFarmHint');
        const sel = document.getElementById('ewelinkFarmSelect');
        const saveBtn = document.getElementById('ewelinkFarmSaveBtn');
        if (!hint || !sel || !saveBtn) {
            return;
        }
        hint.textContent = 'Загрузка устройств…';
        sel.disabled = true;
        saveBtn.disabled = true;

        let res;
        try {
            const r = await fetch(`${API}/v2/integrations/ewelink.php?action=devices`);
            res = await r.json().catch(() => ({}));
            if (!r.ok) {
                throw new Error(res.message || res.msg || ('HTTP ' + r.status));
            }
        } catch (e) {
            hint.textContent = 'Список устройств недоступен: ' + (e && e.message ? e.message : String(e));
            ewelinkFarmClearSelect(sel);
            return;
        }

        const devices = Array.isArray(res.devices) ? res.devices : [];
        const meta = res.meta || {};
        const currentId = farm.ewelink_device_id != null && farm.ewelink_device_id !== ''
            ? String(farm.ewelink_device_id)
            : '';

        ewelinkFarmClearSelect(sel);
        for (let i = 0; i < devices.length; i++) {
            const d = devices[i];
            const id = String(d.deviceId || '');
            if (!id) {
                continue;
            }
            const baseName = d.name || id;
            const off = d.online === false ? ' (offline)' : '';
            const pm = d.productModel ? ' · ' + d.productModel : '';
            const label = baseName + off + pm;
            const o = document.createElement('option');
            o.value = id;
            o.textContent = label;
            o.dataset.ewelinkName = baseName + (d.productModel ? ' · ' + d.productModel : '');
            o.dataset.ewelinkItemType = String(d.itemType === 2 ? 2 : 1);
            sel.appendChild(o);
        }

        if (currentId && !devices.some(function (dd) { return String(dd.deviceId || '') === currentId; })) {
            const o = document.createElement('option');
            o.value = currentId;
            const orphanLabel = (farm.ewelink_device_name || currentId) + ' (сохранено, нет в списке)';
            o.textContent = orphanLabel;
            o.dataset.ewelinkName = farm.ewelink_device_name || currentId;
            o.dataset.ewelinkItemType = String(Number(farm.ewelink_device_item_type) === 2 ? 2 : 1);
            sel.appendChild(o);
        }

        sel.value = currentId;
        if (currentId && sel.value !== currentId) {
            sel.value = '';
        }

        if (devices.length) {
            hint.textContent = 'Выберите устройство и нажмите «Сохранить». В списке: ' + devices.length + '.';
        } else if (meta.thingRows > 0 && meta.refs === 0) {
            hint.textContent = 'API вернул записи (things: ' + meta.thingRows + '), но без отдельных устройств — возможно, в доме только группы или нестандартный формат. Обновите Farmpulse с сервера.';
        } else if (meta.thingRows > 0) {
            hint.textContent = 'Записей: ' + meta.thingRows + ', ссылок на устройства: ' + (meta.refs != null ? meta.refs : '?') + ', в списке пусто — проверьте ответ API на сервере.';
        } else {
            hint.textContent = 'В аккаунте нет устройств или API вернул пустой список (проверьте приложение eWeLink и регион аккаунта).';
        }
        sel.disabled = false;
        saveBtn.disabled = false;
    }

    async function refreshEwelinkFarmDevices() {
        const data = await fetch(`${API}/v2/farms/workers.php?farm_id=${encodeURIComponent(farmId)}`).then(function (r) {
            return r.json();
        });
        const farm = data.farm || {};
        await populateEwelinkDeviceSelect(farm);
    }

    async function saveEwelinkFarmDevice() {
        const sel = document.getElementById('ewelinkFarmSelect');
        const okBanner = document.getElementById('ewelinkBindOk');
        if (!sel) {
            return;
        }
        const id = sel.value.trim();
        const opt = sel.options[sel.selectedIndex];
        let name = '';
        if (opt && opt.dataset && opt.dataset.ewelinkName) {
            name = opt.dataset.ewelinkName;
        } else if (opt) {
            name = opt.textContent || '';
        }
        const itemType = opt && opt.dataset && opt.dataset.ewelinkItemType
            ? (parseInt(opt.dataset.ewelinkItemType, 10) === 2 ? 2 : 1)
            : 1;
        if (okBanner) {
            okBanner.classList.add('d-none');
            okBanner.textContent = '';
        }
        const payload = {
            farm_id: farmId,
            action: 'set_ewelink_device',
            ewelink_device_id: id,
            ewelink_device_name: name,
            ewelink_device_item_type: id ? itemType : 1
        };
        const res = await fetch(`${API}/v2/farms/command.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        if (!res.ok) {
            alert('Ошибка: ' + await res.text());
            return;
        }
        if (okBanner) {
            if (id) {
                okBanner.textContent = 'Сохранено в конфигурации фермы: устройство «' + name + '» привязано. Панель «Розетка» выше использует эту привязку.';
                okBanner.classList.remove('d-none');
            } else {
                okBanner.classList.add('d-none');
            }
        }
        refreshFarm();
    }

    async function saveFlight(apply) {
        const payload = {
            farm_id: farmId,
            miner: document.getElementById('fMiner').value.trim(),
            pool: document.getElementById('fPool').value.trim(),
            wallet: document.getElementById('fWallet').value.trim(),
            pass: document.getElementById('fPass').value,
            coin: document.getElementById('fCoin').value.trim(),
            apply: !!apply
        };
        const res = await fetch(`${API}/v2/farms/flight.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        if (!res.ok) { alert('Failed to save: ' + await res.text()); return; }
        if (apply) alert('Flight sheet saved and apply queued'); else alert('Flight sheet saved');
    }

    async function clearFlight() {
        if (!confirm('Clear flight sheet?')) return;
        const res = await fetch(`${API}/v2/farms/flight.php`, {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ farm_id: farmId })
        });
        if (!res.ok) { alert('Failed: ' + await res.text()); return; }
        document.getElementById('fMiner').value = '';
        document.getElementById('fPool').value = '';
        document.getElementById('fWallet').value = '';
        document.getElementById('fPass').value = 'x';
        document.getElementById('fCoin').value = '';
    }

    async function queueReboot() {
        if (!confirm('Reboot this farm?')) return;
        const res = await fetch(`${API}/v2/farms/command.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ farm_id: farmId, action: 'reboot' })
        });
        if (!res.ok) { alert('Failed: ' + await res.text()); return; }
        alert('Reboot queued');
    }

    window.refreshFarm = refreshFarm;
    window.saveFlight = saveFlight;
    window.clearFlight = clearFlight;
    window.queueReboot = queueReboot;
    window.refreshEwelinkFarmDevices = refreshEwelinkFarmDevices;
    window.saveEwelinkFarmDevice = saveEwelinkFarmDevice;
    window.farmEwelinkSwitch = farmEwelinkSwitch;
    window.farmEwelinkRefreshStatus = farmEwelinkRefreshStatus;
    window.farmEwelinkToggle = farmEwelinkToggle;

    document.addEventListener('DOMContentLoaded', () => {
        if (!farmId) { alert('No farm id'); location.href = 'index.php'; return; }
        refreshFarm();
    });
})();
