(function () {
    'use strict';
    const API = (typeof window.API_BASE === 'string' ? window.API_BASE.replace(/\/$/, '') : '/api');

    const farmId = new URLSearchParams(location.search).get('id') || window.__FARM_ID__ || '';

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

    function buildGpuTableRows(st) {
        const tbody = document.getElementById('gpuTableBody');
        if (!tbody) return;
        if (!st || !Array.isArray(st.temp)) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-muted px-3 py-2">Нет данных temp[]</td></tr>';
            return;
        }
        const temps = st.temp.slice(1);
        const fans = (st.fan && Array.isArray(st.fan)) ? st.fan.slice(1) : [];
        const powers = (st.power && Array.isArray(st.power)) ? st.power.slice(1) : [];
        const n = Math.max(temps.length, fans.length, powers.length, 0);
        if (n === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-muted px-3 py-2">Нет GPU в stats</td></tr>';
            return;
        }
        let html = '';
        for (let i = 0; i < n; i++) {
            const t = temps[i];
            const fan = fans[i];
            const w = powers[i];
            const tStr = (t != null && t !== '' && Number(t) > 0) ? (String(t) + '°') : '—';
            const fStr = (fan != null && fan !== '') ? (String(fan) + '%') : '—';
            const wStr = (w != null && w !== '' && Number(w) > 0) ? String(Math.round(Number(w))) : '—';
            html += `<tr><td>${i}</td><td>${tStr}</td><td>${fStr}</td><td>${wStr}</td><td class="text-muted">—</td></tr>`;
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
        document.getElementById('gpusOnline').textContent = temps.length;
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

        buildGpuTableRows(st);

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

    document.addEventListener('DOMContentLoaded', () => {
        if (!farmId) { alert('No farm id'); location.href = 'index.php'; return; }
        refreshFarm();
    });
})();
