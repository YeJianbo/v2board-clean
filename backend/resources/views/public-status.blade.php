<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>{{ $title }}</title>
  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Ccircle cx='32' cy='32' r='24' fill='%2320a464'/%3E%3C/svg%3E">
  <style>
    :root {
      color-scheme: light;
      font-family: "Noto Sans SC", "PingFang SC", "Microsoft YaHei", sans-serif;
      background: #f4f7f6;
      color: #17251d;
    }

    * { box-sizing: border-box; }
    body { margin: 0; min-width: 320px; }
    main { width: min(980px, calc(100% - 32px)); margin: 0 auto; padding: 64px 0; }
    header { margin-bottom: 24px; }
    h1 { margin: 0; font-size: clamp(24px, 5vw, 34px); letter-spacing: -.03em; }
    .monitor-update { margin: 8px 0 0; color: #718077; font-size: 12px; line-height: 1.5; }
    .monitor-update.is-stale { color: #967244; }
    .machines { display: grid; gap: 10px; }
    .machine {
      display: grid;
      grid-template-columns: 10px minmax(180px, .9fr) minmax(210px, .85fr) minmax(300px, 1.2fr);
      align-items: center;
      gap: 14px;
      min-width: 0;
      padding: 15px 16px;
      border: 1px solid #dfe8e2;
      border-radius: 12px;
      background: #fff;
      box-shadow: 0 1px 2px rgba(17, 39, 26, .03);
      transition: border-color .2s ease, background-color .2s ease;
    }
    .indicator { width: 9px; height: 9px; border-radius: 999px; background: #aeb8b2; }
    .machine.is-online .indicator { background: #20a464; box-shadow: 0 0 0 4px rgba(32, 164, 100, .12); }
    .identity { display: grid; min-width: 0; grid-template-columns: 24px minmax(0, 1fr); align-items: center; column-gap: 9px; }
    .flag {
      grid-row: 1 / span 2;
      display: block;
      width: 24px;
      height: 18px;
      overflow: hidden;
      object-fit: contain;
    }
    .name { grid-column: 2; min-width: 0; overflow: hidden; color: #1c2d23; font-size: 15px; font-weight: 650; text-overflow: ellipsis; white-space: nowrap; }
    .country { grid-column: 2; overflow: hidden; color: #718077; font-size: 12px; text-overflow: ellipsis; white-space: nowrap; }
    .metrics { display: grid; min-width: 0; gap: 6px; }
    .metric { display: grid; grid-template-columns: 34px minmax(50px, 1fr) 38px; align-items: center; gap: 7px; color: #718077; font-size: 11px; }
    .metric-label { font-weight: 600; }
    .metric-bar { height: 5px; overflow: hidden; border-radius: 999px; background: #e8efeb; }
    .metric-fill { display: block; height: 100%; border-radius: inherit; background: #6aa8e8; transition: width .35s ease; }
    .metric-fill.mem { background: #68c796; }
    .metric-value { color: #405249; font-family: Consolas, monospace; text-align: right; font-variant-numeric: tabular-nums; }
    .metric-value.is-empty { color: #a4afa8; }
    .facts { display: grid; min-width: 0; grid-template-columns: 30px minmax(96px, .8fr) minmax(150px, 1fr); gap: 8px; }
    .fact { display: grid; min-width: 0; gap: 3px; }
    .fact-label { color: #89968e; font-size: 10px; font-weight: 650; letter-spacing: .04em; }
    .fact-value { overflow: hidden; color: #405249; font-size: 12px; text-overflow: ellipsis; white-space: nowrap; }
    .system-icon { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 7px; background: #f3f7f5; }
    .system-icon img { display: block; width: 19px; height: 19px; object-fit: contain; }
    .network-values { display: flex; min-width: 0; gap: 8px; color: #405249; font-family: Consolas, monospace; font-size: 11px; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .network-values span { overflow: hidden; text-overflow: ellipsis; }
    .machine.is-offline { border-color: #e3e7e4; background: #f7f8f7; box-shadow: none; }
    .machine.is-offline .flag { filter: grayscale(1); opacity: .5; }
    .machine.is-offline .system-icon { filter: grayscale(1); opacity: .55; }
    .machine.is-offline .name,
    .machine.is-offline .metric-value,
    .machine.is-offline .fact-value,
    .machine.is-offline .network-values { color: #89928d; }
    .machine.is-offline .metric-fill { background: #bac1bd; }
    .empty { padding: 32px 18px; border: 1px dashed #cfdad3; border-radius: 12px; color: #718077; text-align: center; }
    @media (prefers-reduced-motion: reduce) {
      .machine, .metric-fill { transition: none; }
    }
    @media (max-width: 780px) {
      .machine { grid-template-columns: 10px minmax(150px, 1fr) minmax(180px, 1fr); }
      .facts { grid-column: 2 / -1; }
    }
    @media (max-width: 520px) {
      main { width: min(100% - 24px, 980px); padding: 36px 0; }
      .machine { grid-template-columns: 10px minmax(0, 1fr); padding: 14px 12px; gap: 10px; }
      .metrics,
      .facts { grid-column: 2; }
      .facts { grid-template-columns: 30px minmax(0, 1fr); }
      .facts .fact:last-child { grid-column: 1 / -1; }
    }
  </style>
</head>
<body>
  <main>
    <header>
      <h1>主机监控</h1>
      <p class="monitor-update" aria-live="off">正在确认最新状态…</p>
    </header>

    <div id="monitor-world-map"></div>
    <section class="machines" aria-label="主机状态">
      @forelse ($machines as $machine)
        @include('partials.public-machine-card', ['machine' => $machine])
      @empty
        <div class="empty">暂无可展示的主机状态</div>
      @endforelse
    </section>
    <template id="machine-card-template">
      @include('partials.public-machine-card', ['machine' => [
        'id' => 0, 'is_online' => false, 'name' => '', 'country' => '', 'country_code' => '',
        'system' => '', 'cpu' => null, 'mem' => null, 'load' => '', 'net_in' => '', 'net_out' => '',
      ]])
    </template>
  </main>

  <script src="/assets/monitor-map-loader.js?v=20261005-1"></script>
  <script src="/assets/monitor-poll.js?v=20261005-2"></script>
  <script>
    (() => {
      const list = document.querySelector('.machines');
      if (!list) return;
      const updateStatus = document.querySelector('.monitor-update');
      let lastUpdated = '';
      const cardTemplate = document.querySelector('#machine-card-template').content.querySelector('.machine');
      const updateMap = (rows) => window.panelCoreMonitorMap(document.getElementById('monitor-world-map'), rows, (machine) => {
        const card = Array.from(list.querySelectorAll('[data-machine-id]')).find(card => card.dataset.machineId === String(machine.id));
        card?.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
      });
      updateMap(Array.from(list.querySelectorAll('[data-machine-id]')).map(card => ({
        id: card.dataset.machineId, country_code: card.dataset.countryCode, is_online: card.classList.contains('is-online'),
        name: card.querySelector('[data-field="name"]')?.textContent || card.querySelector('.name')?.textContent,
        country: card.querySelector('[data-field="country"]')?.textContent,
      })));

      const setText = (card, field, value) => {
        const element = card.querySelector(`[data-field="${field}"]`);
        if (element && element.textContent !== value) element.textContent = value;
      };
      const resolveSystemIcon = (value) => {
        const system = String(value || '').trim().toLowerCase();
        if (system.includes('debian')) return 'debian';
        if (system.includes('alpine')) return 'alpine';
        if (system.includes('ubuntu')) return 'ubuntu';
        if (system.includes('centos')) return 'centos';
        if (system.includes('rocky')) return 'rocky';
        if (system.includes('alma')) return 'alma';
        if (system.includes('fedora')) return 'fedora';
        if (system.includes('arch linux')) return 'arch';
        if (system.includes('freebsd')) return 'freebsd';
        if (system.includes('opensuse') || system.includes('suse')) return 'opensuse';
        if (system.includes('windows')) return 'windows';
        if (system.includes('linux')) return 'linux';
        return 'server';
      };
      const updateMetric = (card, field, value) => {
        const numericValue = Number(value);
        const hasValue = value !== null && value !== '' && Number.isFinite(numericValue);
        const textElement = card.querySelector(`[data-field="${field}"]`);
        const barElement = card.querySelector(`[data-field="${field}-bar"]`);
        if (textElement) {
          const text = hasValue ? `${numericValue}%` : '--';
          if (textElement.textContent !== text) textElement.textContent = text;
          textElement.classList.toggle('is-empty', !hasValue);
        }
        const width = `${hasValue ? Math.max(0, Math.min(100, numericValue)) : 0}%`;
        if (barElement && barElement.style.width !== width) barElement.style.width = width;
      };

      const refresh = async (signal) => {
          const response = await fetch('/status.json', { cache: 'no-store', signal, headers: { Accept: 'application/json' } });
          if (!response.ok) throw new Error(`HTTP ${response.status}`);
          const payload = await response.json();
          if (signal.aborted) return;
          if (!Array.isArray(payload.data)) throw new Error('Invalid monitor response');
          const machines = payload.data;
          const cards = new Map(Array.from(list.querySelectorAll('[data-machine-id]')).map(card => [card.dataset.machineId, card]));
          const nextIds = new Set(machines.map(machine => String(machine.id)));
          cards.forEach((card, id) => { if (!nextIds.has(id)) card.remove(); });
          if (machines.length) list.querySelector('.empty')?.remove();
          else if (!list.querySelector('.empty')) {
            const empty = document.createElement('div');
            empty.className = 'empty'; empty.textContent = '暂无可展示的主机状态'; list.append(empty);
          }

          machines.forEach((machine, index) => {
            const id = String(machine.id);
            let card = cards.get(id);
            if (!card) { card = cardTemplate.cloneNode(true); card.dataset.machineId = id; }
            if (list.children[index] !== card) list.insertBefore(card, list.children[index] || null);
            const code = String(machine.country_code || '').toUpperCase();
            card.dataset.countryCode = code;
            let flag = card.querySelector('.flag');
            if (/^[A-Z]{2}$/.test(code)) {
              if (!flag) {
                flag = document.createElement('img'); flag.className = 'flag'; flag.alt = ''; flag.decoding = 'async'; flag.setAttribute('role', 'img');
                card.querySelector('.identity').prepend(flag);
              }
              const source = `/assets/flags/${code.toLowerCase()}.svg`;
              if (flag.getAttribute('src') !== source) flag.setAttribute('src', source);
              const country = machine.country || '未知地区';
              if (flag.getAttribute('aria-label') !== country) flag.setAttribute('aria-label', country);
            } else {
              flag?.remove();
            }
            const online = Boolean(machine.is_online);
            card.classList.toggle('is-online', online);
            card.classList.toggle('is-offline', !online);
            const indicator = card.querySelector('.indicator');
            if (indicator) indicator.setAttribute('aria-label', online ? '在线' : '离线');
            setText(card, 'name', machine.name || 'Machine');
            setText(card, 'country', machine.country || '未知地区');
            const systemIcon = card.querySelector('[data-field="system"]');
            if (systemIcon) {
              const systemName = machine.system || '未知系统';
              const iconName = resolveSystemIcon(systemName);
              const iconImage = systemIcon.querySelector('img');
              if (iconImage && systemIcon.dataset.systemIcon !== iconName) {
                iconImage.src = `/assets/os/${iconName}.svg`;
                systemIcon.dataset.systemIcon = iconName;
              }
              systemIcon.setAttribute('title', systemName);
              systemIcon.setAttribute('aria-label', systemName);
            }
            setText(card, 'load', machine.load || '--');
            setText(card, 'net-in', `↓ ${machine.net_in || '--'}`);
            setText(card, 'net-out', `↑ ${machine.net_out || '--'}`);
            updateMetric(card, 'cpu', machine.cpu);
            updateMetric(card, 'mem', machine.mem);
          });
          updateMap(machines);
      };
      // Preserve the last status on errors; hidden pages neither poll nor overlap requests.
      window.panelCoreMonitorPolling(refresh, {
        interval: 5000, timeout: 12000,
        onSuccess() {
          lastUpdated = new Date().toLocaleTimeString('zh-CN', { hour12: false });
          updateStatus.setAttribute('aria-live', 'off');
          updateStatus.classList.remove('is-stale');
          updateStatus.textContent = `更新于 ${lastUpdated}`;
        },
        onError() {
          updateStatus.setAttribute('aria-live', 'polite');
          updateStatus.classList.add('is-stale');
          const message = lastUpdated
            ? `更新失败，显示 ${lastUpdated} 的数据；稍后自动重试`
            : '暂时无法更新，保留页面数据；稍后自动重试';
          if (updateStatus.textContent !== message) updateStatus.textContent = message;
        },
      });
    })();
  </script>
</body>
</html>
