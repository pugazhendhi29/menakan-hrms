<?php
/** Dashboard header widgets: a classic analogue clock (UK time) and the local weather card. app.js keeps both live. */
use App\Services\Weather;

$now = uk_now();
$home = Weather::defaultLocation();
$h = (int) $now->format('G') % 12;
$m = (int) $now->format('i');
$s = (int) $now->format('s');
?>
<div class="clock-card" data-analog-clock>
  <svg class="clock-face" viewBox="0 0 120 120" role="img" aria-label="Clock showing <?= e($now->format('H:i')) ?> UK time">
    <defs>
      <radialGradient id="clk-dial" cx="50%" cy="38%" r="70%"><stop offset="0" stop-color="#ffffff"/><stop offset="1" stop-color="#eef3fa"/></radialGradient>
      <linearGradient id="clk-rim" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#2a4566"/><stop offset="1" stop-color="#14283f"/></linearGradient>
    </defs>
    <circle cx="60" cy="60" r="58" fill="url(#clk-rim)"/>
    <circle cx="60" cy="60" r="52.5" fill="url(#clk-dial)"/>
    <circle cx="60" cy="60" r="52.5" fill="none" stroke="rgba(20,40,63,.12)"/>
    <g stroke="#14283f" stroke-linecap="round">
      <?php for ($i = 0; $i < 60; $i++): $major = $i % 5 === 0; ?>
        <line x1="60" y1="<?= $major ? 11.5 : 12 ?>" x2="60" y2="<?= $major ? 18.5 : 14.5 ?>" stroke-width="<?= $major ? 2.6 : 1 ?>" opacity="<?= $major ? 1 : .45 ?>" transform="rotate(<?= $i * 6 ?> 60 60)"/>
      <?php endfor; ?>
    </g>
    <g class="clock-num" font-size="9.5" font-weight="700" fill="#14283f" text-anchor="middle" dominant-baseline="central">
      <text x="60" y="27">12</text><text x="93" y="60">3</text><text x="60" y="93">6</text><text x="27" y="60">9</text>
    </g>
    <text x="60" y="76" text-anchor="middle" font-size="5.2" font-weight="700" letter-spacing=".9" fill="#d62828">MENAKAN</text>
    <line class="hand hand-h" data-hand="h" x1="60" y1="64" x2="60" y2="34" stroke="#14283f" stroke-width="4.2" stroke-linecap="round" transform="rotate(<?= $h * 30 + $m / 2 ?> 60 60)"/>
    <line class="hand hand-m" data-hand="m" x1="60" y1="66" x2="60" y2="22" stroke="#1e3a5a" stroke-width="2.8" stroke-linecap="round" transform="rotate(<?= $m * 6 + $s / 10 ?> 60 60)"/>
    <g class="hand hand-s" data-hand="s" transform="rotate(<?= $s * 6 ?> 60 60)">
      <line x1="60" y1="70" x2="60" y2="18" stroke="#d62828" stroke-width="1.2" stroke-linecap="round"/>
      <circle cx="60" cy="72.5" r="2.4" fill="#d62828"/>
    </g>
    <circle cx="60" cy="60" r="3.6" fill="#d62828"/><circle cx="60" cy="60" r="1.4" fill="#fff"/>
  </svg>
  <div class="clock-text">
    <small data-clock-date><?= e($now->format('l')) ?></small>
    <b data-clock><?= e($now->format('H:i')) ?></b>
    <span><?= e($now->format('j F Y')) ?> · UK</span>
  </div>
</div>

<div class="weather-card" data-weather data-url="<?= e(url('/weather')) ?>" data-home="<?= e($home['place']) ?>" aria-live="polite">
  <div class="wx-main">
    <span class="wx-icon" data-wx-icon><i class="bi bi-wx-cloud-sun" aria-hidden="true"></i></span>
    <div>
      <b class="wx-temp" data-wx-temp>--°</b>
      <small class="wx-label" data-wx-label>Loading weather…</small>
    </div>
  </div>
  <div class="wx-meta">
    <span data-wx-place><i class="bi bi-geo-alt" aria-hidden="true"></i> <?= e($home['place']) ?></span>
    <span data-wx-range hidden></span>
    <span class="wx-extra" data-wx-extra hidden></span>
  </div>
  <button class="wx-locate" type="button" data-wx-locate title="Show the weather where you are" hidden><i class="bi bi-crosshair" aria-hidden="true"></i> <span>Use my location</span></button>
</div>
