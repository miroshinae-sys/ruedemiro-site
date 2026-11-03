<?php
// Расчёт доставки: город СДЭК, цены по тарифам и список пунктов выдачи
require __DIR__ . '/lib.php';
certs_due();

$city = trim((string)($_GET['city'] ?? ''));
$method = (string)($_GET['method'] ?? '');
$n = max(1, min(20, (int)($_GET['n'] ?? 1)));
if (mb_strlen($city) < 2) out(['ok' => false, 'error' => 'city'], 400);
if (!cdek_base()) out(['ok' => false, 'error' => 'off']);

$c = cdek_city($city);
if (!$c) out(['ok' => false, 'error' => 'notfound']);
$cc = cfg()['cdek'];
$prices = [];
foreach (['cdek_pvz' => $cc['tariff_pvz'], 'cdek_courier' => $cc['tariff_courier']] as $m => $tariff) {
    $p = cdek_price((int)$c['code'], (int)$tariff, $n);
    if ($p) $prices[$m] = $p;
}
$points = null;
if ($method === 'cdek_pvz' || $method === '') {
    $raw = cdek_get('/deliverypoints', ['city_code' => $c['code'], 'type' => 'PVZ', 'is_handout' => 'true']) ?: [];
    $points = [];
    foreach ($raw as $p) {
        $points[] = ['code' => $p['code'], 'name' => $p['name'] ?? '', 'address' => $p['location']['address'] ?? ($p['location']['address_full'] ?? ''),
                     'metro' => $p['nearest_metro_station'] ?? '', 'work_time' => $p['work_time'] ?? '',
                     'lat' => isset($p['location']['latitude']) ? (float)$p['location']['latitude'] : null, 'lon' => isset($p['location']['longitude']) ? (float)$p['location']['longitude'] : null];
    }
    usort($points, fn($a, $b) => strcmp($a['address'], $b['address']));
}
$far = ship_far(['code' => $c['code'], 'name' => $c['city'], 'region' => $c['region'] ?? '']);
out(['ok' => true, 'city' => ['code' => $c['code'], 'name' => $c['city'], 'region' => $c['region'] ?? '', 'far' => $far,
     'free' => ['cdek_pvz' => ship_free_from('cdek_pvz', $far), 'cdek_courier' => ship_free_from('cdek_courier', $far)]], 'prices' => $prices, 'points' => $points]);
