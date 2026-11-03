<?php
// Создание заказа и переход к оплате
require __DIR__ . '/lib.php';
certs_due();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'Метод не поддерживается'], 405);

$c = cfg();
$in = json_decode((string)file_get_contents('php://input', false, null, 0, 20000), true);
if (!is_array($in)) out(['ok' => false, 'error' => 'Некорректный запрос'], 400);

// простая защита от спама по IP
$ip = $_SERVER['REMOTE_ADDR'] ?? '0';
$rl = orders_dir() . '/.rate_' . md5($ip) . '.json';
$hits = is_file($rl) ? array_filter(json_decode((string)file_get_contents($rl), true) ?: [], fn($t) => $t > time() - 3600) : [];
if (count($hits) >= $c['rate_limit']) out(['ok' => false, 'error' => 'Слишком много заказов подряд. Напишите нам на salut@ruedemiro.com.'], 429);

$cut = fn($v, $n) => mb_substr(trim(strip_tags((string)$v)), 0, $n);
$cu = $in['customer'] ?? [];
$name = $cut($cu['name'] ?? '', 120); $phone = $cut($cu['phone'] ?? '', 40); $email = $cut($cu['email'] ?? '', 120);
$tg = ltrim($cut($cu['telegram'] ?? '', 40), '@');
if ($tg !== '' && !preg_match('/^[A-Za-z0-9_]{4,32}$/', $tg)) out(['ok' => false, 'error' => 'Проверьте ник в Telegram.']);
if (mb_strlen($name) < 2) out(['ok' => false, 'error' => 'Укажите имя и фамилию.']);
if (strlen(preg_replace('/\D/', '', $phone)) < 10) out(['ok' => false, 'error' => 'Укажите телефон.']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) out(['ok' => false, 'error' => 'Проверьте адрес электронной почты.']);
if (empty($in['agree']) || empty($in['consent'])) out(['ok' => false, 'error' => 'Нужно согласие с офертой и на обработку персональных данных.']);

// коды: промокод на первый заказ и (или) подарочный сертификат
$promo = null; $cert = null;
foreach (array_slice((array)($in['codes'] ?? []), 0, 3) as $code) {
    $x = code_lookup((string)$code);
    if (!$x) out(['ok' => false, 'error' => 'Код ' . mb_substr((string)$code, 0, 20) . ' не найден. Проверьте его или удалите.']);
    if (!empty($x['error'])) out(['ok' => false, 'error' => $x['error']]);
    if ($x['type'] === 'welcome' && !$promo) $promo = $x;
    if ($x['type'] === 'cert' && !$cert) $cert = $x;
}
$t = price_order(is_array($in['items'] ?? null) ? $in['items'] : [], $promo);
if ($t['empty']) out(['ok' => false, 'error' => 'Корзина пуста.']);

$TITLES = ['cdek_pvz' => 'СДЭК — пункт выдачи', 'cdek_courier' => 'СДЭК — курьер'];
$d = $in['delivery'] ?? [];
$method = (string)($d['method'] ?? '');
if (!isset($TITLES[$method])) out(['ok' => false, 'error' => 'Выберите способ доставки.']);
$city = ['code' => isset($d['city']['code']) ? (int)$d['city']['code'] : null, 'name' => $cut($d['city']['name'] ?? '', 80)];
$pvz = !empty($d['pvz']['code']) ? ['code' => $cut($d['pvz']['code'], 20), 'address' => $cut($d['pvz']['address'] ?? '', 200)] : null;
$pvzManual = $cut($d['pvzManual'] ?? '', 200); $address = $cut($d['address'] ?? '', 250);
if (mb_strlen($city['name']) < 2) out(['ok' => false, 'error' => 'Укажите город.']);
if ($method === 'cdek_pvz' && !$pvz && mb_strlen($pvzManual) < 3) out(['ok' => false, 'error' => 'Выберите пункт выдачи.']);
if ($method === 'cdek_courier' && mb_strlen($address) < 5) out(['ok' => false, 'error' => 'Укажите адрес доставки.']);

$dprice = delivery_price($method, $city, (int)$t['sum'], $t['units']);
$total = $t['sum'] + $dprice;
$certAmt = $cert ? min($cert['balance'], $total) : 0;
$id = 'RDM-' . date('ymd') . '-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
while (is_file(order_path($id))) $id = 'RDM-' . date('ymd') . '-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);

$o = [
    'id' => $id, 'key' => bin2hex(random_bytes(8)), 'created_at' => date('c'), 'status' => 'new',
    'type' => 'goods', 'items' => $t['items'], 'lines' => $t['lines'], 'disc' => $t['disc'], 'full' => $t['full'], 'sum' => $t['sum'], 'units' => $t['units'], 'promo' => $t['promo'],
    'delivery' => ['method' => $method, 'title' => $TITLES[$method], 'price' => $dprice, 'city' => $city, 'pvz' => $pvz, 'pvzManual' => $pvzManual, 'address' => $address],
    'total' => $total, 'to_pay' => $total - $certAmt, 'cert_used' => $certAmt ? ['code' => $cert['code'], 'amount' => $certAmt] : null,
    'customer' => ['name' => $name, 'phone' => $phone, 'email' => $email, 'telegram' => $tg ? '@' . $tg : ''],
    'comment' => $cut($in['comment'] ?? '', 500),
    'consent' => ['offer' => true, 'pd' => true, 'at' => date('c'), 'ip' => $ip, 'ua' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200)],
];
if ($certAmt && !cert_hold($cert['code'], $id, $certAmt)) out(['ok' => false, 'error' => 'Не удалось списать сумму с сертификата. Обновите страницу и попробуйте ещё раз.']);
order_save($o);
$hits[] = time(); file_put_contents($rl, json_encode(array_values($hits)));

// всё оплачено сертификатом — карта не нужна
if ($o['to_pay'] === 0) {
    $o = order_mark_paid($o);
    out(['ok' => true, 'order' => $id, 'key' => $o['key'], 'payUrl' => null]);
}

// без онлайн-оплаты: заказ принят, счёт отправите вручную
if (!alfa_url()) {
    $o['status'] = 'accepted'; order_save($o);
    mail_order($o, false);
    out(['ok' => true, 'order' => $id, 'key' => $o['key'], 'payUrl' => null]);
}

$back = rtrim($c['site'], '/') . '/thanks?order=' . $id . '&k=' . $o['key'];
$params = [
    'orderNumber' => $id, 'amount' => $o['to_pay'] * 100, 'currency' => 643, 'language' => 'ru',
    'returnUrl' => $back, 'failUrl' => $back . '&fail=1',
    'description' => 'Заказ ' . $id . ' на ruedemiro.com', 'email' => $email,
    'phone' => preg_replace('/\D/', '', $phone),
];
if ($c['alfa']['send_bundle']) { $params['orderBundle'] = json_encode(alfa_bundle($o), JSON_UNESCAPED_UNICODE); $params['taxSystem'] = $c['alfa']['tax_system']; }
$r = alfa_call('register.do', $params);
if (!$r || empty($r['formUrl'])) {
    $o['status'] = 'error'; $o['alfa_error'] = $r['errorMessage'] ?? 'no response'; order_save($o);
    if ($certAmt) cert_release($cert['code'], $id);
    mail_send($c['shop_email'], "Заказ $id — ошибка оплаты", "Банк не создал платёж: " . $o['alfa_error'] . "\n\n" . order_text($o), $email);
    out(['ok' => false, 'error' => 'Не удалось открыть оплату. Попробуйте ещё раз через минуту или напишите нам на salut@ruedemiro.com.']);
}
$o['alfa'] = ['orderId' => $r['orderId'], 'mode' => $c['alfa']['mode']];
order_save($o);
mail_order($o, false);
out(['ok' => true, 'order' => $id, 'key' => $o['key'], 'payUrl' => $r['formUrl']]);
