(function () {
    'use strict';
    const API = (typeof window.API_BASE === 'string' ? window.API_BASE.replace(/\/$/, '') : '/api');

    const farmId = new URLSearchParams(location.search).get('id') || window.__FARM_ID__ || '';

    function gpuDot(temp) {
        const t = Number(temp);
        const color = isNaN(t) ? '#6c757d' : (t >= 80 ? '#dc3545' : (t >= 60 ? '#ffc107' : '#198754'));
        return `<span class="gpu-dot" style="background:${color}"></span>`;
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

        const ri = farm.rig_info;
        const rigEl = document.getElementById('rigInfoBox');
        if (ri && typeof ri === 'object') {
            const g = (ri.gpu_count_nvidia != null || ri.gpu_count_amd != null)
                ? ('GPU: NV ' + (ri.gpu_count_nvidia ?? '—') + ' / AMD ' + (ri.gpu_count_amd ?? '—') + ' / Intel ' + (ri.gpu_count_intel ?? '—'))
                : '';
            const ver = ri.version ? ('Hive ' + ri.version) : '';
            rigEl.textContent = [g, ver, ri.kernel || '', ri.image_version || ''].filter(Boolean).join(' · ') || JSON.stringify(ri);
        } else rigEl.textContent = '—';

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
