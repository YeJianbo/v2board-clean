@php
  $countryCode = strtoupper((string) ($machine['country_code'] ?? ''));
  $flagCode = strtolower($countryCode);
  $hasFlag = preg_match('/^[a-z]{2}$/i', $countryCode) === 1;
  $systemName = strtolower(trim((string) ($machine['system'] ?? '')));
  $systemIcon = match (true) {
    str_contains($systemName, 'debian') => 'debian',
    str_contains($systemName, 'alpine') => 'alpine',
    str_contains($systemName, 'ubuntu') => 'ubuntu',
    str_contains($systemName, 'centos') => 'centos',
    str_contains($systemName, 'rocky') => 'rocky',
    str_contains($systemName, 'alma') => 'alma',
    str_contains($systemName, 'fedora') => 'fedora',
    str_contains($systemName, 'arch linux') => 'arch',
    str_contains($systemName, 'freebsd') => 'freebsd',
    str_contains($systemName, 'opensuse'), str_contains($systemName, 'suse') => 'opensuse',
    str_contains($systemName, 'windows') => 'windows',
    str_contains($systemName, 'linux') => 'linux',
    default => 'server',
  };
@endphp
<article
  class="machine {{ $machine['is_online'] ? 'is-online' : 'is-offline' }}"
  data-machine-id="{{ $machine['id'] }}"
  data-country-code="{{ $countryCode }}"
>
  <span class="indicator" aria-label="{{ $machine['is_online'] ? '在线' : '离线' }}"></span>
  <div class="identity">
    @if ($hasFlag)
      <img class="flag" src="/assets/flags/{{ $flagCode }}.svg" alt="" role="img" aria-label="{{ $machine['country'] }}" decoding="async">
    @endif
    <span class="name" data-field="name">{{ $machine['name'] }}</span>
    <span class="country" data-field="country">{{ $machine['country'] }}</span>
  </div>
  <div class="metrics" aria-label="资源占用">
    <div class="metric">
      <span class="metric-label">CPU</span>
      <span class="metric-bar"><span class="metric-fill" data-field="cpu-bar" style="width: {{ $machine['cpu'] ?? 0 }}%"></span></span>
      <span class="metric-value {{ $machine['cpu'] === null ? 'is-empty' : '' }}" data-field="cpu">{{ $machine['cpu'] === null ? '--' : $machine['cpu'] . '%' }}</span>
    </div>
    <div class="metric">
      <span class="metric-label">内存</span>
      <span class="metric-bar"><span class="metric-fill mem" data-field="mem-bar" style="width: {{ $machine['mem'] ?? 0 }}%"></span></span>
      <span class="metric-value {{ $machine['mem'] === null ? 'is-empty' : '' }}" data-field="mem">{{ $machine['mem'] === null ? '--' : $machine['mem'] . '%' }}</span>
    </div>
  </div>
  <div class="facts">
    <div class="fact fact--system">
      <span class="system-icon" data-field="system" data-system-icon="{{ $systemIcon }}" title="{{ $machine['system'] }}" role="img" aria-label="{{ $machine['system'] }}">
        <img src="/assets/os/{{ $systemIcon }}.svg" alt="" decoding="async">
      </span>
    </div>
    <div class="fact">
      <span class="fact-label">负载</span>
      <span class="fact-value" data-field="load">{{ $machine['load'] }}</span>
    </div>
    <div class="fact">
      <span class="fact-label">实时网络</span>
      <span class="network-values">
        <span data-field="net-in">↓ {{ $machine['net_in'] }}</span>
        <span data-field="net-out">↑ {{ $machine['net_out'] }}</span>
      </span>
    </div>
  </div>
</article>
