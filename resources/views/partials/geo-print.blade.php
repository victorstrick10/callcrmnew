@php
  $g = $geo ?? [];
  $cn = trim((string) ($g['country'] ?? ''));
  $cc = trim((string) ($g['countryCode'] ?? ''));
  $countryText = ($cn !== '' && $cc !== '' && strtoupper($cn) !== strtoupper($cc))
    ? $cn.' ('.$cc.')'
    : ($cn !== '' ? $cn : ($cc !== '' ? $cc : '—'));
  $rc = trim((string) ($g['regionCode'] ?? ''));
  $rn = trim((string) ($g['regionName'] ?? ''));
  $regionText = trim(($rc !== '' ? $rc.' · ' : '').$rn);
  if ($regionText === '') { $regionText = '—'; }
  $city = trim((string) ($g['city'] ?? ''));
  $zip = trim((string) ($g['zip'] ?? ''));
  $isp = trim((string) ($g['isp'] ?? ''));
  $ip = trim((string) ($g['ip'] ?? ''));
@endphp
<div class="geo-lines geo-print">
  <span><b>Country</b>{{ \App\Support\CountryFlag::emoji($cc) }} {{ $countryText }}</span>
  <span><b>Region</b>{{ $regionText }}</span>
  <span><b>City</b>{{ $city !== '' ? $city : '—' }}</span>
  @if ($zip !== '')<span><b>ZIP</b>{{ $zip }}</span>@endif
  <span><b>ISP</b>{{ $isp !== '' ? $isp : '—' }}</span>
  @if ($ip !== '')<span><b>IP</b>{{ $ip }}</span>@endif
</div>
