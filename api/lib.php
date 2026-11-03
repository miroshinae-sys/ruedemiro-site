<?php
// Общие функции магазина RUE DE MIRÓ
declare(strict_types=1);
date_default_timezone_set('Europe/Moscow');

function cfg(): array {
    static $c = null;
    if ($c === null) {
        $c = require __DIR__ . '/config.php';
        $local = __DIR__ . '/config.local.php';   // необязательный файл для локальных ключей
        if (is_file($local)) $c = array_replace_recursive($c, require $local);
        // значения по умолчанию для настроек, которых может не быть в старом config.php на хостинге
        $c += ['welcome' => ['percent' => 10, 'days' => 60]];
        // доставка: с какой суммы товаров бесплатно (0 — всегда бесплатно)
        $c += ['ship' => ['pvz_free' => 0, 'courier_free' => 5000, 'far_pvz_free' => 3500, 'far_courier_free' => 7000,
            'far_regions' => ['Камчат', 'Магадан', 'Сахалин', 'Чукот', 'Саха', 'Якут', 'Амурск', 'Еврейск', 'Хабаровск', 'Приморск', 'Калининград'],
            'far_cities' => ['Норильск']]];
        $c += ['promo_codes' => ['SALUT10' => ['percent' => 10, 'until' => '2027-12-31']]];
        $c += ['smtp' => ['host' => '', 'port' => 465, 'user' => '', 'pass' => '']];
        $c += ['trays' => [
            'place'  => ['name' => 'Поднос Place RUE DE MIRÓ, нержавеющая сталь, 20,4 × 15,6 см', 'price' => 990],
            'ruelle' => ['name' => 'Поднос Ruelle RUE DE MIRÓ, нержавеющая сталь, 20 × 8,5 см', 'price' => 590],
        ]];
        $c += ['sets' => [   // готовые наборы = наборы конструктора с названием
            'set_petite'  => ['name' => 'Petite Rue',     'kit' => 'core.recharge:ruelle'],
            'set_grande3' => ['name' => 'Grande Rue · 3', 'kit' => 'core.recharge.balance:ruelle'],
            'set_grande5' => ['name' => 'Grande Rue · 5', 'kit' => 'core.recharge.escape.weightless.balance:place'],
        ]];
        $c += ['cert' => [
            'max' => 50000, 'months' => 12,
            'payment_method' => 3,   // признак способа расчёта при продаже сертификата: 3 — аванс (уточните у бухгалтера)
            'payment_object' => 10,  // признак предмета расчёта: 10 — платёж
        ]];
    }
    return $c;
}

function out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function orders_dir(): string {
    $d = __DIR__ . '/orders';
    if (!is_dir($d)) mkdir($d, 0700, true);
    return $d;
}

function rub(int $v): string { return ($v >= 10000 ? number_format($v, 0, ',', ' ') : (string)$v) . ' ₽'; }

function http_req(string $method, string $url, array $opt = []): array {
    $ch = curl_init();
    $headers = $opt['headers'] ?? [];
    if (isset($opt['json'])) { $headers[] = 'Content-Type: application/json'; $body = json_encode($opt['json'], JSON_UNESCAPED_UNICODE); }
    elseif (isset($opt['form'])) { $body = http_build_query($opt['form']); $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }
    else $body = null;
    if ($method === 'GET' && !empty($opt['query'])) $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($opt['query']);
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) return ['ok' => false, 'code' => 0, 'error' => $err, 'data' => null];
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'data' => json_decode((string)$res, true), 'raw' => $res];
}

// ---------- цены ----------
// Позиции корзины: масла (core…), подносы (tray_place, tray_ruelle), готовые наборы (set_…),
// наборы из конструктора: kit:<масла через точку>:<none|ruelle|place>, например kit:core.core.escape:ruelle
function discount(int $n): int {
    foreach (cfg()['discounts'] as $from => $pct) if ($n >= $from) return (int)$pct;
    return 0;
}
function kit_parse(string $key): ?array {
    if (!preg_match('/^kit:([a-z.]{3,80}):(none|ruelle|place)$/', $key, $m)) return null;
    $oils = explode('.', $m[1]); $P = cfg()['products'];
    foreach ($oils as $o) if (!isset($P[$o])) return null;
    if (count($oils) < 2 || count($oils) > 5) return null;
    sort($oils);
    return ['oils' => $oils, 'tray' => $m[2]];
}
function kit_price(array $k): array {
    $c = cfg(); $n = count($k['oils']); $d = discount($n);
    $oils = (int)round($n * $c['price'] * (100 - $d) / 100);
    $tf = $k['tray'] === 'none' ? 0 : $c['trays'][$k['tray']]['price'];
    $tp = $k['tray'] === 'none' ? 0 : ($n === 2 ? (int)round($tf / 2) : ($n === 3 ? ($k['tray'] === 'ruelle' ? 0 : 400) : 0));
    return ['price' => $oils + $tp, 'full' => $n * $c['price'] + $tf, 'disc' => $d, 'tray_price' => $tp, 'n' => $n];
}
function kit_title(array $k): string {
    $P = cfg()['products']; $cnt = array_count_values($k['oils']); $parts = [];
    foreach ($cnt as $o => $q) $parts[] = strtoupper($o) . ($q > 1 ? ' ×' . $q : '');
    return implode(', ', $parts) . ($k['tray'] === 'none' ? '' : ' + поднос ' . ucfirst($k['tray']));
}
// промокод на первый заказ (из подписки) или подарочный сертификат
function code_lookup(string $code): ?array {
    $code = strtoupper(trim($code)); if ($code === '') return null;
    $pc = cfg()['promo_codes'][$code] ?? null;
    if ($pc) {
        if (!empty($pc['until']) && $pc['until'] < date('Y-m-d')) return ['type' => 'welcome', 'code' => $code, 'error' => 'Срок действия промокода закончился.'];
        return ['type' => 'welcome', 'code' => $code, 'percent' => (int)$pc['percent'], 'until' => $pc['until'] ?? null];
    }
    if (preg_match('/^MIRO-[A-Z0-9]{6}$/', $code)) {
        $all = is_file(subs_path()) ? (json_decode((string)file_get_contents(subs_path()), true) ?: []) : [];
        foreach ($all as $s) if (($s['code'] ?? '') === $code) {
            if (!empty($s['code_used'])) return ['type' => 'welcome', 'code' => $code, 'error' => 'Этот код уже использован.'];
            if (($s['code_until'] ?? '') < date('Y-m-d')) return ['type' => 'welcome', 'code' => $code, 'error' => 'Срок действия кода закончился.'];
            return ['type' => 'welcome', 'code' => $code, 'percent' => (int)cfg()['welcome']['percent'], 'until' => $s['code_until']];
        }
        return null;
    }
    if (preg_match('/^GC-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code)) {
        $g = cert_get($code);
        if (!$g || $g['status'] !== 'active') return null;
        if ($g['until'] < date('Y-m-d')) return ['type' => 'cert', 'code' => $code, 'error' => 'Срок действия сертификата закончился.'];
        $bal = cert_available($g);
        if ($bal <= 0) return ['type' => 'cert', 'code' => $code, 'error' => 'На сертификате не осталось средств.'];
        return ['type' => 'cert', 'code' => $code, 'balance' => $bal, 'until' => $g['until']];
    }
    return null;
}
// расчёт заказа: строки для чека (в копейках), суммы, бесплатная доставка
function price_order(array $items, ?array $promo = null): array {
    $c = cfg(); $lines = []; $looseN = 0; $loose = []; $trays = []; $kits = []; $units = 0;
    foreach ($items as $id => $q) {
        $q = (int)$q; if ($q <= 0) continue; $q = min($q, 20); $id = (string)$id;
        if (isset($c['products'][$id])) { $loose[$id] = $q; $looseN += $q; $units += $q; }
        elseif (preg_match('/^tray_(place|ruelle)$/', $id, $m)) { $trays[$m[1]] = $q; $units += $q; }
        elseif (isset($c['sets'][$id])) { $kits[] = [$id, $c['sets'][$id]['name'], kit_parse('kit:' . $c['sets'][$id]['kit']), $q]; }
        elseif (($k = kit_parse($id))) { $kits[] = ['kit:' . implode('.', $k['oils']) . ':' . $k['tray'], null, $k, $q]; }
    }
    $wp = ($promo && ($promo['type'] ?? '') === 'welcome' && empty($promo['error'])) ? (int)$promo['percent'] : 0;
    $dv = discount($looseN); $d = $looseN ? max($dv, $wp) : 0; $promoUsed = $wp > 0 && (($looseN > 0 && $wp > $dv) || $trays);
    $full = 0; $goods = 0; $freeShip = false;
    // масла поштучно: цена за флакон в копейках точная, итог округляем до рубля одной строкой-поправкой
    if ($looseN) {
        $unitK = $c['price'] * (100 - $d);            // копейки
        $target = (int)round($looseN * $unitK / 100) * 100;
        $diff = $target - $looseN * $unitK;           // от −50 до +50 копеек
        foreach ($loose as $id => $q) {
            $name = $c['products'][$id];
            if ($diff !== 0) { if ($q > 1) $lines[] = ['code' => $id, 'name' => $name, 'qty' => $q - 1, 'price' => $unitK, 'object' => 1];
                               $lines[] = ['code' => $id, 'name' => $name, 'qty' => 1, 'price' => $unitK + $diff, 'object' => 1]; $diff = 0; }
            else $lines[] = ['code' => $id, 'name' => $name, 'qty' => $q, 'price' => $unitK, 'object' => 1];
        }
        $full += $looseN * $c['price']; $goods += $target / 100;
    }
    foreach ($trays as $t => $q) {
        $pr = $c['trays'][$t]['price']; $pk = (int)round($pr * (100 - $wp) / 100) * 100;
        $lines[] = ['code' => 'tray_' . $t, 'name' => $c['trays'][$t]['name'], 'qty' => $q, 'price' => $pk, 'object' => 1];
        $full += $pr * $q; $goods += $pk / 100 * $q;
    }
    foreach ($kits as [$code, $title, $k, $q]) {
        $kp = kit_price($k);
        $name = 'Набор RUE DE MIRÓ' . ($title ? ' ' . $title : '') . ': ' . kit_title($k);
        $lines[] = ['code' => $code, 'name' => mb_substr($name, 0, 100), 'qty' => $q, 'price' => $kp['price'] * 100, 'object' => 1];
        $full += $kp['full'] * $q; $goods += $kp['price'] * $q; $units += $kp['n'] * $q + ($k['tray'] === 'none' ? 0 : $q);
        if ($k['tray'] !== 'none') $freeShip = true;
    }
    foreach ($lines as &$l) $l['amount'] = $l['price'] * $l['qty']; unset($l);
    $goods = (int)round($goods);
    return ['lines' => $lines, 'items' => $items, 'loose_n' => $looseN, 'disc' => $looseN ? $d : 0, 'full' => $full, 'sum' => $goods,
            'save' => $full - $goods, 'units' => max(1, $units), 'free_ship' => $freeShip || $goods >= $c['free_from'],
            'promo' => $wp ? ['code' => $promo['code'], 'percent' => $wp, 'applied' => $promoUsed] : null, 'empty' => !$lines];
}
// подогнать строки чека под сумму к оплате картой (если часть оплачена сертификатом)
function lines_fit(array $lines, int $targetK): array {
    $total = array_sum(array_column($lines, 'amount')); if ($total <= 0 || $targetK >= $total) return $lines;
    $out = []; $left = $targetK; $n = count($lines);
    foreach ($lines as $i => $l) {
        $amt = $i === $n - 1 ? $left : (int)floor($l['amount'] * $targetK / $total); $left -= $amt;
        $q = $l['qty']; $p = intdiv($amt, $q); $r = $amt - $p * $q;
        if ($r === 0) $out[] = array_merge($l, ['price' => $p, 'amount' => $amt]);
        else { if ($q > 1) $out[] = array_merge($l, ['qty' => $q - 1, 'price' => $p, 'amount' => $p * ($q - 1)]);
               $out[] = array_merge($l, ['qty' => 1, 'price' => $p + $r, 'amount' => $p + $r]); }
    }
    return array_values(array_filter($out, fn($l) => $l['amount'] > 0));
}

function cdek_base(): ?string {
    $m = cfg()['cdek']['mode'];
    if (!empty(cfg()['cdek']['base'])) return cfg()['cdek']['base'];   // для отладки
    if ($m === 'prod') return 'https://api.cdek.ru/v2';
    if ($m === 'test') return 'https://api.edu.cdek.ru/v2';
    return null;
}

function cdek_token(): ?string {
    $base = cdek_base(); if (!$base) return null;
    $c = cfg()['cdek'];
    $cache = orders_dir() . '/.cdek_token_' . md5($base . $c['client_id']) . '.json';
    if (is_file($cache)) {
        $t = json_decode((string)file_get_contents($cache), true);
        if ($t && $t['exp'] > time() + 60) return $t['token'];
    }
    $r = http_req('POST', $base . '/oauth/token', ['form' => ['grant_type' => 'client_credentials', 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret']]]);
    if (!$r['ok'] || empty($r['data']['access_token'])) return null;
    file_put_contents($cache, json_encode(['token' => $r['data']['access_token'], 'exp' => time() + (int)($r['data']['expires_in'] ?? 3000)]));
    return $r['data']['access_token'];
}

function cdek_get(string $path, array $query = []): ?array {
    $tok = cdek_token(); if (!$tok) return null;
    $r = http_req('GET', cdek_base() . $path, ['query' => $query, 'headers' => ['Authorization: Bearer ' . $tok]]);
    return $r['ok'] ? $r['data'] : null;
}

function cdek_city(string $name): ?array {
    $list = cdek_get('/location/cities', ['city' => $name, 'country_codes' => 'RU', 'size' => 10]);
    if (!$list) return null;
    // точное совпадение названия — в приоритете
    foreach ($list as $c) if (mb_strtolower($c['city']) === mb_strtolower(trim($name))) return $c;
    return $list[0] ?? null;
}

// город отправки: код из настроек или поиск по названию (from_city)
function cdek_from_code(): int {
    $c = cfg()['cdek'];
    if (!empty($c['from_city_code'])) return (int)$c['from_city_code'];
    $f = cdek_city((string)($c['from_city'] ?? 'Москва'));
    return $f ? (int)$f['code'] : 44;
}

function cdek_price(int $city_code, int $tariff, int $n): ?array {
    $tok = cdek_token(); if (!$tok) return null;
    $c = cfg()['cdek']; $n = max(1, $n);
    $r = http_req('POST', cdek_base() . '/calculator/tariff', ['headers' => ['Authorization: Bearer ' . $tok], 'json' => [
        'tariff_code' => $tariff,
        'from_location' => ['code' => cdek_from_code()],
        'to_location' => ['code' => $city_code],
        'packages' => [['weight' => $c['item_weight'] * $n, 'length' => $c['length'], 'width' => $c['width'], 'height' => $c['height'] * (int)ceil($n / 2)]],
    ]]);
    if (!$r['ok'] || !isset($r['data']['total_sum'])) return null;
    $min = $r['data']['period_min'] ?? null; $max = $r['data']['period_max'] ?? null;
    $days = $min ? ($min == $max ? $min : $min . '–' . $max) . ' дн.' : '';
    return ['price' => (int)ceil((float)$r['data']['total_sum']), 'days' => $days];
}

// цена доставки, которую считаем на сервере (клиентской не доверяем)
// дальний регион: по городу и региону из СДЭК (без связи со СДЭК — по названию города)
function ship_far(array $city): bool {
    static $cache = [];
    $s = cfg()['ship']; $name = (string)($city['name'] ?? ''); $region = (string)($city['region'] ?? '');
    $code = (int)($city['code'] ?? 0);
    if ($code && $region === '' && cdek_base()) {
        if (!isset($cache[$code])) { $r = cdek_get('/location/cities', ['code' => $code, 'size' => 1]); $cache[$code] = $r[0] ?? []; }
        $region = (string)($cache[$code]['region'] ?? ''); if (!empty($cache[$code]['city'])) $name = (string)$cache[$code]['city'];
    }
    foreach ($s['far_cities'] as $fc) if ($name !== '' && mb_strtolower(trim($name)) === mb_strtolower($fc)) return true;
    foreach ($s['far_regions'] as $fr) if (($region !== '' && mb_stripos($region, $fr) !== false)) return true;
    return false;
}
function ship_free_from(string $method, bool $far): int {
    $s = cfg()['ship'];
    if ($method === 'cdek_courier') return (int)($far ? $s['far_courier_free'] : $s['courier_free']);
    return (int)($far ? $s['far_pvz_free'] : $s['pvz_free']);
}
function delivery_price(string $method, array $city, int $goods, int $n): int {
    $c = cfg();
    if ($method === 'pickup' || $goods >= ship_free_from($method, ship_far($city))) return 0;
    if (($method === 'cdek_pvz' || $method === 'cdek_courier') && !empty($city['code']) && cdek_base()) {
        $p = cdek_price((int)$city['code'], (int)$c['cdek'][$method === 'cdek_pvz' ? 'tariff_pvz' : 'tariff_courier'], $n);
        if ($p) return $p['price'];
    }
    return (int)($c['flat'][$method] ?? 0);
}

// ---------- Альфа-Банк ----------
function alfa_url(): ?string {
    $a = cfg()['alfa'];
    if ($a['mode'] === 'prod') return $a['prod_url'];
    if ($a['mode'] === 'test') return $a['test_url'];
    return null;
}

function alfa_call(string $method, array $params): ?array {
    $u = alfa_url(); if (!$u) return null;
    $a = cfg()['alfa'];
    $auth = !empty($a['token']) ? ['token' => $a['token']] : ['userName' => $a['user'], 'password' => $a['password']];
    $params = array_merge($auth, $params);
    $r = http_req('POST', $u . $method, ['form' => $params]);
    return is_array($r['data']) ? $r['data'] : null;
}

function alfa_bundle(array $o): array {
    $c = cfg(); $a = $c['alfa']; $items = []; $pos = 1;
    $pm = (string)($o['type'] === 'cert' ? $c['cert']['payment_method'] : ($a['payment_method'] ?? 4));
    $lines = $o['lines'];
    if (!empty($o['delivery']['price'])) $lines[] = ['code' => 'delivery', 'name' => 'Доставка', 'qty' => 1, 'price' => $o['delivery']['price'] * 100, 'amount' => $o['delivery']['price'] * 100, 'object' => 4];
    if (!empty($o['cert_used'])) $lines = lines_fit($lines, $o['to_pay'] * 100);
    foreach ($lines as $l) {
        $items[] = ['positionId' => (string)$pos++, 'name' => $l['name'], 'quantity' => ['value' => $l['qty'], 'measure' => $a['measure']],
            'itemAmount' => $l['amount'], 'itemCode' => mb_substr($l['code'], 0, 50), 'itemPrice' => $l['price'], 'tax' => ['taxType' => $a['tax_type']],
            'itemAttributes' => ['attributes' => [['name' => 'paymentMethod', 'value' => $pm], ['name' => 'paymentObject', 'value' => (string)$l['object']]]]];
    }
    return ['customerDetails' => ['email' => $o['customer']['email'], 'phone' => preg_replace('/\D/', '', $o['customer']['phone'] ?? '')], 'cartItems' => ['items' => $items]];
}

// ---------- заказы ----------
function order_path(string $id): string {
    if (!preg_match('/^RDM-\d{6}-\d{4}$/', $id)) throw new RuntimeException('bad id');
    return orders_dir() . '/' . $id . '.json';
}
function order_load(string $id): ?array {
    try { $p = order_path($id); } catch (Throwable $e) { return null; }
    return is_file($p) ? json_decode((string)file_get_contents($p), true) : null;
}
function order_save(array $o): void {
    file_put_contents(order_path($o['id']), json_encode($o, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

// обновить статус оплаты из банка; при первой успешной оплате — письма
function order_refresh(array $o): array {
    if (in_array($o['status'], ['paid', 'accepted'], true) || empty($o['alfa']['orderId'])) return $o;
    $r = alfa_call('getOrderStatusExtended.do', ['orderId' => $o['alfa']['orderId']]);
    if (!$r || !isset($r['orderStatus'])) return $o;
    $st = (int)$r['orderStatus'];
    $amountOk = !isset($r['amount']) || (int)$r['amount'] === $o['to_pay'] * 100;
    if ($st === 2 && $amountOk) {
        $o = order_mark_paid($o);
    } elseif (in_array($st, [3, 6], true)) {
        $o['status'] = 'failed'; order_save($o);
        if (!empty($o['cert_used'])) cert_release($o['cert_used']['code'], $o['id']);
    }
    return $o;
}

function order_mark_paid(array $o): array {
    $o['status'] = 'paid'; $o['paid_at'] = date('c');
    order_save($o);
    if (($o['type'] ?? '') === 'cert') { cert_issue($o); return $o; }
    if (!empty($o['cert_used'])) cert_spend($o['cert_used']['code'], $o['id'], $o['cert_used']['amount']);
    if (!empty($o['promo']['applied'])) welcome_use($o['promo']['code'], $o['id']);
    mail_order($o, true);
    return $o;
}

// ---------- письма ----------
function mail_send(string $to, string $subject, string $text, string $replyTo = ''): bool {
    return mail_out($to, $subject, "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64", chunk_split(base64_encode($text)), $replyTo);
}

// Отправка письма: через SMTP почтового ящика магазина (если указан в config.php → smtp) — так письма подписаны DKIM
// и доходят до Gmail; иначе — через mail() хостинга. Каждая попытка пишется в orders/mail.log.
function mail_out(string $to, string $subject, string $ctypeHeaders, string $body, string $replyTo = ''): bool {
    $c = cfg(); $from = $c['mail_from']; $dom = substr(strrchr($from, '@'), 1);
    $h = "MIME-Version: 1.0\r\n" . $ctypeHeaders . "\r\n"
       . 'From: =?UTF-8?B?' . base64_encode('RUE DE MIRÓ') . '?= <' . $from . ">\r\n"
       . ($replyTo ? "Reply-To: $replyTo\r\n" : '')
       . 'Date: ' . date('r') . "\r\n" . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $dom . ">\r\n";
    $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $sm = $c['smtp'] ?? [];
    if (!empty($sm['host']) && !empty($sm['user']) && !empty($sm['pass'])) {
        $err = '';
        $ok = smtp_send($sm, $from, $to, "To: $to\r\nSubject: $subj\r\n" . $h, $body, $err);
        mail_log($to, $subject, $ok, 'smtp' . ($err ? ': ' . $err : ''));
        if ($ok) return true;
    }
    $ok = @mail($to, $subj, $body, rtrim($h), '-f' . $from);
    mail_log($to, $subject, $ok, 'mail()');
    return $ok;
}
function mail_log(string $to, string $subject, bool $ok, string $how): void {
    @file_put_contents(orders_dir() . '/mail.log', date('c') . ' ' . ($ok ? 'OK  ' : 'FAIL') . " $how → $to · $subject\n", FILE_APPEND | LOCK_EX);
}
function smtp_send(array $sm, string $from, string $to, string $headers, string $body, string &$err): bool {
    $port = (int)($sm['port'] ?? 465); $secure = $sm['secure'] ?? ($port === 465 ? 'ssl' : 'tls');
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $sm['host'] . ':' . $port, $en, $es, 15);
    if (!$fp) { $err = "connect $es"; return false; }
    stream_set_timeout($fp, 15);
    $read = function () use ($fp) { $r = ''; while (($l = fgets($fp, 1024)) !== false) { $r .= $l; if (isset($l[3]) && $l[3] === ' ') break; } return $r; };
    $cmd = function (string $c, array $want) use ($fp, $read, &$err) { if ($c !== '') fwrite($fp, $c . "\r\n"); $r = $read(); if (!in_array((int)substr($r, 0, 3), $want, true)) { $err = trim((preg_match('/^(EHLO|STARTTLS|MAIL|RCPT|DATA)/', $c) ? $c . ' → ' : '') . $r); return false; } return true; };
    $host = gethostname() ?: 'localhost';
    if (!$cmd('', [220]) || !$cmd("EHLO $host", [250])) { fclose($fp); return false; }
    if ($secure === 'tls') {
        if (!$cmd('STARTTLS', [220]) || !stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) || !$cmd("EHLO $host", [250])) { $err = $err ?: 'starttls'; fclose($fp); return false; }
    }
    $ok = $cmd('AUTH LOGIN', [334]) && $cmd(base64_encode($sm['user']), [334]) && $cmd(base64_encode($sm['pass']), [235])
       && $cmd("MAIL FROM:<$from>", [250]) && $cmd("RCPT TO:<$to>", [250, 251]) && $cmd('DATA', [354]);
    if ($ok) { fwrite($fp, $headers . "\r\n" . preg_replace('/^\./m', '..', rtrim($body, "\r\n")) . "\r\n.\r\n"); $ok = $cmd('', [250]); if (!$ok && $err === '') $err = 'no reply after DATA'; }
    @fwrite($fp, "QUIT\r\n"); fclose($fp);
    return $ok;
}

function order_text(array $o): string {
    $l = [];
    foreach ($o['lines'] as $x) $l[] = '• ' . $x['name'] . ' — ' . $x['qty'] . ' шт. = ' . rub((int)round($x['amount'] / 100));
    $d = $o['delivery'];
    $where = trim(($d['city']['name'] ?? '') . ', ' . ($d['pvz']['address'] ?? $d['pvzManual'] ?? $d['address'] ?? ''), ', ');
    if (!empty($d['pvz']['code'])) $where .= ' (ПВЗ ' . $d['pvz']['code'] . ')';
    return implode("\n", $l) . "\n\n"
        . 'Товары без скидок: ' . rub($o['full']) . ($o['full'] > $o['sum'] ? "\nСкидка: −" . rub($o['full'] - $o['sum']) : '') . "\n"
        . (!empty($o['promo']['applied']) ? 'Промокод ' . $o['promo']['code'] . " (−{$o['promo']['percent']}%)\n" : '')
        . 'Доставка (' . $d['title'] . '): ' . ($d['price'] ? rub($d['price']) : 'бесплатно') . "\n"
        . 'Итого: ' . rub($o['total']) . "\n"
        . (!empty($o['cert_used']) ? 'Оплачено сертификатом ' . $o['cert_used']['code'] . ': ' . rub($o['cert_used']['amount']) . "\nК оплате картой: " . rub($o['to_pay']) . "\n" : '') . "\n"
        . 'Получатель: ' . $o['customer']['name'] . ', ' . $o['customer']['phone'] . ', ' . $o['customer']['email'] . (!empty($o['customer']['telegram']) ? ', Telegram ' . $o['customer']['telegram'] . ' (https://t.me/' . ltrim($o['customer']['telegram'], '@') . ')' : '') . "\n"
        . 'Адрес: ' . $where . "\n"
        . ($o['comment'] ? 'Комментарий: ' . $o['comment'] . "\n" : '');
}

function mail_order(array $o, bool $paid): void {
    $c = cfg(); $t = order_text($o);
    $state = $paid ? 'ОПЛАЧЕН' : ($o['status'] === 'accepted' ? 'принят, ждёт счёта на оплату' : 'создан, ждёт оплаты');
    $note = ($paid && !empty($o['cert_used'])) ? "\n\nВНИМАНИЕ: часть заказа (" . rub($o['cert_used']['amount']) . ") оплачена подарочным сертификатом {$o['cert_used']['code']}. На эту сумму нужно пробить чек «зачёт аванса» в кабинете кассы.\n" : '';
    mail_send($c['shop_email'], "Заказ {$o['id']} — $state", "Заказ {$o['id']} $state.$note\n\n$t", $o['customer']['email']);
    if ($paid || $o['status'] === 'accepted') {
        $hello = 'Здравствуйте, ' . $o['customer']['name'] . "!\n\n"
            . ($paid ? "Спасибо за заказ {$o['id']} — оплата получена. Мы соберём его и передадим в доставку в течение 1–3 рабочих дней, а затем пришлём номер для отслеживания. Кассовый чек придёт отдельным письмом.\n\n"
                     : "Спасибо за заказ {$o['id']}! Мы свяжемся с вами, чтобы подтвердить состав и доставку, и пришлём ссылку на оплату.\n\n");
        mail_send($o['customer']['email'], "RUE DE MIRÓ — заказ {$o['id']}", $hello . $t . "\nВопросы — ответьте на это письмо, напишите в Telegram: @ruedemiro_salut (https://t.me/ruedemiro_salut)\n\nRUE DE MIRÓ\n" . $c['site'], $c['shop_email']);
    }
}

// ---------- подписка ----------
function subs_path(): string { return orders_dir() . '/subscribers.json'; }
function subs_edit(callable $fn) {
    $fh = fopen(subs_path(), 'c+'); flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh); $all = json_decode($raw ?: '{}', true) ?: [];
    $res = $fn($all);
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    flock($fh, LOCK_UN); fclose($fh);
    return $res;
}
function sub_page(string $title, string $text): void {
    $site = cfg()['site'];
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>' . htmlspecialchars($title) . ' — RUE DE MIRÓ</title>'
       . '<style>body{margin:0;background:#ECE8E1;color:#1B1A19;font:300 16px/1.6 Montserrat,"Helvetica Neue",Arial,sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px;box-sizing:border-box}'
       . 'main{max-width:560px;background:#FFFFFF;border:1px solid #1B1A19;padding:40px;display:flex;flex-direction:column;gap:18px}h1{margin:0;font:400 34px/1.1 Forum,Georgia,serif;letter-spacing:.03em;text-transform:uppercase}'
       . '.m{font:12px "IBM Plex Mono",monospace;letter-spacing:.06em;color:#4A4846}.code{font:20px "IBM Plex Mono",monospace;letter-spacing:.12em;border:1px solid #1B1A19;padding:10px 16px;align-self:flex-start}a.b{display:inline-flex;align-self:flex-start;min-height:48px;align-items:center;padding:0 24px;background:#1B1A19;color:#fff;text-decoration:none;font-size:12px;font-weight:500;letter-spacing:.18em;text-transform:uppercase}</style></head>'
       . '<body><main><span class="m">[ RUE DE MIRÓ · ПИСЬМО О БЬЮТИ-РУТИНЕ ]</span><h1>' . htmlspecialchars($title) . '</h1>' . $text . '<a class="b" href="' . $site . '/">На сайт</a></main></body></html>';
    exit;
}

function welcome_use(string $code, string $orderId): void {
    subs_edit(function (array &$all) use ($code, $orderId) {
        foreach ($all as $em => $s) if (($s['code'] ?? '') === $code && empty($s['code_used'])) $all[$em]['code_used'] = $orderId . ' ' . date('c');
    });
}

// ---------- подарочные сертификаты ----------
function cert_path(): string { return orders_dir() . '/certs.json'; }
function cert_edit(callable $fn) {
    $fh = fopen(cert_path(), 'c+'); flock($fh, LOCK_EX);
    $all = json_decode(stream_get_contents($fh) ?: '{}', true) ?: [];
    $res = $fn($all);
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    flock($fh, LOCK_UN); fclose($fh);
    return $res;
}
function cert_get(string $code): ?array {
    $all = is_file(cert_path()) ? (json_decode((string)file_get_contents(cert_path()), true) ?: []) : [];
    return $all[$code] ?? null;
}
// доступный остаток: баланс минус суммы, зарезервированные неоплаченными заказами (резерв живёт 3 часа)
function cert_available(array $g): int {
    $held = 0;
    foreach ($g['holds'] ?? [] as $h) if ($h['at'] > time() - 3 * 3600) $held += $h['amount'];
    return max(0, $g['balance'] - $held);
}
function cert_hold(string $code, string $orderId, int $amount): bool {
    return (bool)cert_edit(function (array &$all) use ($code, $orderId, $amount) {
        $g = $all[$code] ?? null; if (!$g || cert_available($g) < $amount) return false;
        $all[$code]['holds'][$orderId] = ['amount' => $amount, 'at' => time()];
        return true;
    });
}
function cert_release(string $code, string $orderId): void {
    cert_edit(function (array &$all) use ($code, $orderId) { unset($all[$code]['holds'][$orderId]); });
}
function cert_spend(string $code, string $orderId, int $amount): void {
    cert_edit(function (array &$all) use ($code, $orderId, $amount) {
        if (!isset($all[$code]) || isset($all[$code]['history'][$orderId])) return;
        unset($all[$code]['holds'][$orderId]);
        $all[$code]['balance'] = max(0, $all[$code]['balance'] - $amount);
        $all[$code]['history'][$orderId] = ['amount' => $amount, 'at' => date('c')];
    });
}
function cert_new_code(): string {
    $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $p = fn() => implode('', array_map(fn() => $a[random_int(0, strlen($a) - 1)], range(1, 4)));
    return 'GC-' . $p() . '-' . $p();
}
// после оплаты: выпустить код; отправить сразу или в выбранный день
function cert_issue(array $o): void {
    $c = cfg(); $g = $o['cert'];
    $code = cert_edit(function (array &$all) use ($o, $g, $c) {
        foreach ($all as $k => $x) if (($x['order'] ?? '') === $o['id']) return $k;   // уже выпущен
        do { $k = cert_new_code(); } while (isset($all[$k]));
        $start = max(date('Y-m-d'), $g['send_date'] ?: date('Y-m-d'));
        $all[$k] = ['code' => $k, 'order' => $o['id'], 'status' => 'active', 'amount' => $o['total'], 'balance' => $o['total'],
            'until' => date('Y-m-d', strtotime($start . ' +' . $c['cert']['months'] . ' months')), 'design' => $g['design'],
            'to_name' => $g['to_name'], 'to_email' => $g['to_email'], 'from' => $g['from'], 'note' => $g['note'],
            'buyer_email' => $o['customer']['email'], 'send_date' => $start, 'send_now' => empty($g['send_date']), 'sent_at' => null, 'holds' => [], 'history' => []];
        return $k;
    });
    $o['cert']['code'] = $code; order_save($o);
    $x = cert_get($code);
    mail_send($c['shop_email'], "Сертификат {$code} оплачен — заказ {$o['id']}", "Номинал: " . rub($o['total']) . "\nКому: {$x['to_name']} <{$x['to_email']}>\nОт: {$x['from']}\nОтправка: {$x['send_date']}\nПокупатель: {$o['customer']['email']}\n");
    mail_send($o['customer']['email'], 'Подарочный сертификат RUE DE MIRÓ оплачен',
        "Спасибо! Сертификат на " . rub($o['total']) . " для {$x['to_name']} оплачен.\n"
        . ($x['send_date'] > date('Y-m-d') ? "Мы отправим его на {$x['to_email']} " . date('d.m.Y', strtotime($x['send_date'])) . " утром.\n" : "Мы отправили его на {$x['to_email']}.\n")
        . "Код сертификата: {$code} (действует до " . date('d.m.Y', strtotime($x['until'])) . ").\nКассовый чек придёт отдельным письмом.\n\nRUE DE MIRÓ\n{$c['site']}", $c['shop_email']);
    certs_due();
}
// отправить сертификаты, у которых наступил день отправки (вызывается из API и по расписанию из cron.php)
function certs_due(): void {
    if (!is_file(cert_path())) return;
    $all = json_decode((string)file_get_contents(cert_path()), true) ?: [];
    foreach ($all as $k => $x) {
        if (!empty($x['sent_at']) || ($x['status'] ?? '') !== 'active') continue;
        $today = date('Y-m-d');
        $due = $x['send_date'] < $today || ($x['send_date'] === $today && (!empty($x['send_now']) || (int)date('G') >= 10));
        if (!$due) continue;
        if (cert_mail($x)) cert_edit(function (array &$a) use ($k) { $a[$k]['sent_at'] = date('c'); });
    }
}
function cert_mail(array $x): bool {
    $c = cfg(); $site = rtrim($c['site'], '/');
    $img = ['move' => 'card-dvizhenie.jpg', 'reflect' => 'card-otrazhenie.jpg', 'calm' => 'card-tishina.jpg'][$x['design']] ?? 'card-dvizhenie.jpg';
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
    $until = date('d.m.Y', strtotime($x['until']));
    $mono = "'IBM Plex Mono','Courier New',monospace";
    $html = mail_tpl([
        'hero' => 'img/v2/' . $img, 'preheader' => 'Подарочный сертификат RUE DE MIRÓ на ' . rub($x['amount']) . ($x['from'] ? ' от ' . $x['from'] : ''),
        'kicker' => 'ПОДАРОЧНЫЙ СЕРТИФИКАТ', 'title' => 'Вам подарок',
        'html' => '<p style="margin:0">' . $e($x['to_name']) . ', для вас подарочный сертификат RUE DE MIRÓ — на масла для тела с ароматом и наборы.</p>',
        'box' => '<p style="margin:0 0 10px;font-family:Forum,Georgia,serif;font-style:italic;font-size:24px;color:#1B1A19">' . $e($x['to_name']) . ',</p>'
            . ($x['note'] ? '<p style="margin:0 0 10px;font-family:Georgia,serif;font-style:italic;font-size:18px;line-height:1.5;color:#1B1A19">' . nl2br($e($x['note'])) . '</p>' : '')
            . ($x['from'] ? '<p style="margin:0 0 6px;font-family:Georgia,serif;font-size:17px;color:#1B1A19">— ' . $e($x['from']) . '</p>' : '')
            . '<div style="width:70%;margin:16px auto 14px;border-top:1px solid #8C8A87"></div>'
            . '<p style="margin:0 0 12px;font-family:Forum,Georgia,serif;font-size:46px;line-height:1;color:#1B1A19">' . $e(number_format($x['amount'], 0, ',', ' ')) . ' ₽</p>'
            . '<p style="margin:0 0 10px"><span style="display:inline-block;padding:10px 18px;border:1px solid #1B1A19;background:#FFFFFF;font-family:' . $mono . ';font-size:20px;letter-spacing:3px;color:#1B1A19">' . $e($x['code']) . '</span></p>'
            . '<p style="margin:0;font-family:' . $mono . ';font-size:11px;letter-spacing:1px">ДЕЙСТВУЕТ ДО ' . $until . '</p>',
        'button' => ['Выбрать подарок', $site . '/#catalog'],
        'after' => '<p style="margin:0">Выберите масла или набор на сайте и введите код в корзине в поле «Сертификат или промокод». Если заказ меньше номинала, остаток сохранится на коде.</p>',
    ]);
    $text = "{$x['to_name']}, вам подарочный сертификат RUE DE MIRÓ на " . rub($x['amount']) . ($x['from'] ? " от {$x['from']}" : '') . ".\n"
        . ($x['note'] ? "\n{$x['note']}\n" : '') . "\nКод: {$x['code']}\nДействует до $until.\nВведите код в корзине на сайте: $site/\n";
    $ok = mail_html($x['to_email'], 'Вам подарочный сертификат RUE DE MIRÓ', $html, $text, $x['buyer_email'] ?? '');
    if (!empty($x['buyer_email'])) mail_html($x['buyer_email'], 'Копия: подарочный сертификат для ' . $x['to_name'], $html, $text);
    return $ok;
}
// Фирменный HTML-шаблон писем (таблицы и inline-стили — так письмо одинаково выглядит в Gmail, Почте Mail.ru, Яндексе, Apple Mail).
// $o: hero (файл из /img/mail), title, html (основной текст), box (выделенный блок), button [текст, ссылка], sign, unsub (ссылка отписки)
function mail_tpl(array $o): string {
    $site = rtrim(cfg()['site'], '/'); $img = $site . '/img/mail/';
    $serif = "Forum,'Cormorant Garamond',Georgia,'Times New Roman',serif";
    $sans = "Montserrat,'Helvetica Neue',Arial,sans-serif";
    $mono = "'IBM Plex Mono','Courier New',monospace";
    $a = 'color:#1B1A19;text-decoration:none';
    $nav = '';
    foreach ([['Масла', '/#catalog'], ['Наборы', '/nabor'], ['Сертификат', '/gift-card'], ['Подбор&nbsp;формулы', '/quiz']] as $i => $l)
        $nav .= ($i ? '<span style="color:#B5B1AA">&nbsp;· </span>' : '') . '<a href="' . $site . $l[1] . '" style="' . $a . '">' . $l[0] . '</a>';
    $pre = htmlspecialchars($o['preheader'] ?? '', ENT_QUOTES);
    return '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
      . '<meta name="color-scheme" content="light only"><meta name="supported-color-schemes" content="light">'
      . '<link href="https://fonts.googleapis.com/css2?family=Forum&family=Montserrat:wght@300;400;500&family=IBM+Plex+Mono&display=swap" rel="stylesheet">'
      . '<style>@media (max-width:620px){.w{width:100%!important}.p{padding-left:24px!important;padding-right:24px!important}.h1{font-size:26px!important}.f{padding-left:16px!important;padding-right:16px!important}}</style></head>'
      . '<body style="margin:0;padding:0;background:#ECE8E1">'
      . '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . $pre . '</div>'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ECE8E1"><tr><td align="center" style="padding:24px 12px">'
      . '<table role="presentation" class="w" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;background:#FFFFFF">'
      . (!empty($o['hero']) ? '<tr><td><a href="' . $site . '/"><img src="' . (strpos($o['hero'], '/') !== false ? $site . '/' . $o['hero'] : $img . $o['hero']) . '" width="600" alt="RUE DE MIRÓ" style="display:block;width:100%;height:auto;border:0"></a></td></tr>' : '')
      . '<tr><td class="p" style="padding:44px 48px 8px;font-family:' . $sans . ';font-weight:300;font-size:16px;line-height:1.65;color:#1B1A19">'
      . (!empty($o['kicker']) ? '<p style="margin:0 0 14px;font-family:' . $mono . ';font-size:11px;letter-spacing:2px;color:#4A4846">[ ' . $o['kicker'] . ' ]</p>' : '')
      . '<h1 class="h1" style="margin:0 0 22px;font-family:' . $serif . ';font-weight:400;font-size:30px;line-height:1.15;letter-spacing:1px;text-transform:uppercase;color:#1B1A19">' . $o['title'] . '</h1>'
      . $o['html']
      . '</td></tr>'
      . (!empty($o['box']) ? '<tr><td class="p" style="padding:12px 48px 8px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F1EC;border:1px solid #1B1A19"><tr><td align="center" style="padding:28px 20px;font-family:' . $sans . ';font-size:14px;line-height:1.6;color:#4A4846">' . $o['box'] . '</td></tr></table></td></tr>' : '')
      . (!empty($o['button']) ? '<tr><td class="p" align="center" style="padding:28px 48px 8px"><table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="background:#1B1A19"><a href="' . $o['button'][1] . '" style="display:inline-block;padding:16px 34px;font-family:' . $sans . ';font-size:12px;font-weight:500;letter-spacing:3px;text-transform:uppercase;color:#FFFFFF;text-decoration:none">' . $o['button'][0] . '</a></td></tr></table></td></tr>' : '')
      . (!empty($o['after']) ? '<tr><td class="p" style="padding:24px 48px 0;font-family:' . $sans . ';font-weight:300;font-size:15px;line-height:1.65;color:#1B1A19">' . $o['after'] . '</td></tr>' : '')
      . '<tr><td class="p" style="padding:28px 48px 44px;font-family:' . $serif . ';font-size:18px;color:#1B1A19">' . ($o['sign'] ?? 'С любовью, RUE DE MIRÓ') . '</td></tr>'
      // подвал
      . '<tr><td class="f" style="border-top:1px solid #1B1A19;padding:36px 32px 32px;text-align:center;font-family:' . $sans . ';font-size:13px;line-height:1.7;color:#4A4846">'
      . '<a href="' . $site . '/"><img src="' . $img . 'logo.png" width="190" alt="RUE DE MIRÓ" style="display:inline-block;width:190px;height:auto;border:0"></a>'
      . '<p style="margin:22px 0 0;font-family:' . $mono . ';font-size:11px;letter-spacing:1.5px;text-transform:uppercase">' . $nav . '</p>'
      . '<p style="margin:22px 0 0">Вопросы? Ответьте на это письмо или напишите нам<br>'
      . '<a href="https://t.me/ruedemiro_salut" style="' . $a . '">Telegram @ruedemiro_salut</a>&nbsp;· <a href="mailto:salut@ruedemiro.com" style="' . $a . '">salut@ruedemiro.com</a></p>'
      . '<p style="margin:14px 0 0"><a href="https://t.me/ruedemiro" style="' . $a . '">Telegram-канал</a>&nbsp;· <a href="https://instagram.com/ruedemiro/" style="' . $a . '">Instagram*</a>&nbsp;· <a href="https://ru.pinterest.com/RUEDEMIRO/" style="' . $a . '">Pinterest</a></p>'
      . '<p style="margin:22px 0 0;font-size:11px;line-height:1.5;color:#8C8A87">* Instagram принадлежит компании Meta, признанной экстремистской организацией и запрещённой в РФ.</p>'
      . (!empty($o['unsub']) ? '<p style="margin:10px 0 0;font-size:11px;color:#8C8A87">Вы получили это письмо, потому что подписались на рассылку на сайте ruedemiro.com. <a href="' . $o['unsub'] . '" style="color:#8C8A87;text-decoration:underline">Отписаться</a></p>' : '')
      . '</td></tr></table></td></tr></table></body></html>';
}
function mail_html(string $to, string $subject, string $html, string $text, string $replyTo = ''): bool {
    $b = 'b' . bin2hex(random_bytes(8));
    $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
          . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "--$b--\r\n";
    return mail_out($to, $subject, "Content-Type: multipart/alternative; boundary=\"$b\"", $body, $replyTo);
}
