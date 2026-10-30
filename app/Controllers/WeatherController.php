<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Weather;

/** Dashboard weather card (app.js): the company's weather, or the device's when the person shares their location. */
final class WeatherController extends Controller
{
    public function show(Request $request): Response
    {
        $loc = Weather::defaultLocation();
        $lat = filter_var($request->str('lat'), FILTER_VALIDATE_FLOAT);
        $lon = filter_var($request->str('lon'), FILTER_VALIDATE_FLOAT);
        if ($lat !== false && $lon !== false && abs($lat) <= 90 && abs($lon) <= 180) {
            $loc = ['lat' => $lat, 'lon' => $lon, 'place' => 'Your location'];
        }
        $w = Weather::current($loc['lat'], $loc['lon'], $loc['place']);
        return $w ? Response::json($w) : Response::json(['error' => 'Weather is unavailable right now.'], 503);
    }
}
