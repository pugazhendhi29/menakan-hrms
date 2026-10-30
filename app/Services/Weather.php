<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Current weather for the dashboard card, from Open-Meteo (free, no API key). Fetched by the server, so pages
 * never call another site, and cached per location (rounded to about 1 km) for 20 minutes in storage/cache.
 * The default location is the company's (WEATHER_LAT, WEATHER_LON, WEATHER_PLACE in .env).
 */
final class Weather
{
    private const TTL = 1200;

    /** WMO weather codes: [label, day icon, night icon] (icon names are keys in vendor/lucide/icons.js). */
    private const CODES = [
        0 => ['Clear sky', 'wx-sun', 'wx-moon'], 1 => ['Mainly clear', 'wx-sun', 'wx-moon'], 2 => ['Partly cloudy', 'wx-cloud-sun', 'wx-cloud-moon'],
        3 => ['Overcast', 'wx-cloud', 'wx-cloud'], 45 => ['Fog', 'wx-fog', 'wx-fog'], 48 => ['Freezing fog', 'wx-fog', 'wx-fog'],
        51 => ['Light drizzle', 'wx-drizzle', 'wx-drizzle'], 53 => ['Drizzle', 'wx-drizzle', 'wx-drizzle'], 55 => ['Heavy drizzle', 'wx-drizzle', 'wx-drizzle'],
        56 => ['Freezing drizzle', 'wx-drizzle', 'wx-drizzle'], 57 => ['Freezing drizzle', 'wx-drizzle', 'wx-drizzle'],
        61 => ['Light rain', 'wx-rain', 'wx-rain'], 63 => ['Rain', 'wx-rain', 'wx-rain'], 65 => ['Heavy rain', 'wx-rain', 'wx-rain'],
        66 => ['Freezing rain', 'wx-rain', 'wx-rain'], 67 => ['Freezing rain', 'wx-rain', 'wx-rain'],
        71 => ['Light snow', 'wx-snow', 'wx-snow'], 73 => ['Snow', 'wx-snow', 'wx-snow'], 75 => ['Heavy snow', 'wx-snow', 'wx-snow'], 77 => ['Snow grains', 'wx-snow', 'wx-snow'],
        80 => ['Light showers', 'wx-showers', 'wx-showers'], 81 => ['Showers', 'wx-showers', 'wx-showers'], 82 => ['Heavy showers', 'wx-showers', 'wx-showers'],
        85 => ['Snow showers', 'wx-snow', 'wx-snow'], 86 => ['Snow showers', 'wx-snow', 'wx-snow'],
        95 => ['Thunderstorm', 'wx-storm', 'wx-storm'], 96 => ['Thunderstorm, hail', 'wx-storm', 'wx-storm'], 99 => ['Thunderstorm, hail', 'wx-storm', 'wx-storm'],
    ];

    /** @return array{lat: float, lon: float, place: string} */
    public static function defaultLocation(): array
    {
        return ['lat' => (float) Config::get('app.weather.lat'), 'lon' => (float) Config::get('app.weather.lon'), 'place' => (string) Config::get('app.weather.place')];
    }

    /** Current conditions and today's high/low, or null when the weather service cannot be reached and nothing is cached. */
    public static function current(float $lat, float $lon, string $place): ?array
    {
        $lat = round(max(-90, min(90, $lat)), 2);
        $lon = round(max(-180, min(180, $lon)), 2);
        $dir = Config::get('app.storage_path') . '/cache/weather';
        $file = $dir . '/' . str_replace(['.', '-'], ['_', 'm'], "{$lat}_{$lon}") . '.json';
        $cached = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!$cached || time() - (int) ($cached['fetched'] ?? 0) > self::TTL) {
            $fresh = self::fetch($lat, $lon);
            if ($fresh) {
                if (!is_dir($dir)) {
                    @mkdir($dir, 0750, true);
                }
                @file_put_contents($file, json_encode($fresh + ['fetched' => time()]), LOCK_EX);
                $cached = $fresh + ['fetched' => time()];
            }
        }
        if (!$cached) {
            return null;
        }
        [$label, $dayIcon, $nightIcon] = self::CODES[$cached['code']] ?? ['—', 'wx-cloud', 'wx-cloud'];
        return [
            'place' => $place, 'temp' => (int) round($cached['temp']), 'feels' => (int) round($cached['feels']), 'label' => $label,
            'icon' => $cached['is_day'] ? $dayIcon : $nightIcon, 'is_day' => (bool) $cached['is_day'],
            'high' => (int) round($cached['high']), 'low' => (int) round($cached['low']), 'wind' => (int) round($cached['wind']), 'humidity' => (int) $cached['humidity'],
            'updated' => gmdate('Y-m-d H:i:s', (int) $cached['fetched']),
        ];
    }

    private static function fetch(float $lat, float $lon): ?array
    {
        $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
            'latitude' => $lat, 'longitude' => $lon, 'timezone' => 'auto', 'forecast_days' => 1, 'wind_speed_unit' => 'mph',
            'current' => 'temperature_2m,apparent_temperature,relative_humidity_2m,weather_code,is_day,wind_speed_10m',
            'daily' => 'temperature_2m_max,temperature_2m_min',
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_USERAGENT => 'MenakanHRMS/1.0']);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $d = is_string($body) && $status === 200 ? json_decode($body, true) : null;
        $c = $d['current'] ?? null;
        if (!is_array($c) || !isset($c['temperature_2m'], $c['weather_code'])) {
            return null;
        }
        return [
            'temp' => (float) $c['temperature_2m'], 'feels' => (float) ($c['apparent_temperature'] ?? $c['temperature_2m']), 'code' => (int) $c['weather_code'],
            'is_day' => (int) ($c['is_day'] ?? 1), 'wind' => (float) ($c['wind_speed_10m'] ?? 0), 'humidity' => (int) ($c['relative_humidity_2m'] ?? 0),
            'high' => (float) ($d['daily']['temperature_2m_max'][0] ?? $c['temperature_2m']), 'low' => (float) ($d['daily']['temperature_2m_min'][0] ?? $c['temperature_2m']),
        ];
    }
}
