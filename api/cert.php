<?php
// Покупка электронного подарочного сертификата: заказ типа cert и переход к оплате
require __DIR__ . '/lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'Метод не поддерживается'], 405);
$c = cfg();
$in = json_decode((string)file_get_contents('php://input', false, null, 0, 8000), true);
if (!is_array($in)) out(['ok' => false, 'error' => 'Некорректный запрос'], 400);
$ip = $_SERVER['REMOTE_ADDR'] ?? '0';
$rl = orders_dir() . '/.rate_' . md5($ip) . '.json';
$hits = is_file($rl) ? array_filter(json_decode((string)file_get_contents($rl), true) ?: [], fn($t) => $t > time() - 3600) : [];
if (count($hits) >= $c['rate_limit']) out(['ok' => false, 'error' => 'Слишком много попыток подряд. Напишите нам на salut@ruedemiro.com.'], 429);

$cut = fn($v, $n) => mb_substr(trim(strip_tags((string)$v)), 0, $n);
$amount = (int)($in['amount'] ?? 0);
if ((string)$amount !== trim((string)($in['amount'] ?? '')) && !is_int($in['amount'] ?? null)) out(['ok' => false, 'error' => 'Сумма — целое число рублей.']);
if ($amount < 1 || $amount > $c['cert']['max']) out(['ok' => false, 'error' => 'Сумма сертификата — до ' . rub($c['cert']['max']) . '.']);
$design = in_array($in['design'] ?? '', ['move', 'reflect', 'calm'], true) ? $in['design'] : 'move';
$to = $cut($in['to_name'] ?? '', 40); $toEmail = $cut($in['to_email'] ?? '', 120);
$from = $cut($in['from'] ?? '', 40); $note = $cut($in['note'] ?? '', 160);
$buyer = $cut($in['buyer_email'] ?? '', 120);
if (mb_strlen($to) < 1) out(['ok' => false, 'error' => 'Укажите имя получателя.']);
if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) out(['ok' => false, 'error' => 'Проверьте почту получателя.']);
if (!filter_var($buyer, FILTER_VALIDATE_EMAIL)) out(['ok' => false, 'error' => 'Укажите вашу почту — на неё придут чек и копия сертификата.']);
if (empty($in['agree']) || empty($in['consent'])) out(['ok' => false, 'error' => 'Нужно согласие с офертой и на обработку персональных данных.']);
$date = (string)($in['send_date'] ?? '');
if ($date !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < date('Y-m-d') || $date > date('Y-m-d', strtotime('+1 year'))) out(['ok' => false, 'error' => 'Выберите дату отправки — сегодня или позже, но не дальше чем через год.']);
}
$id = 'RDM-' . date('ymd') . '-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
while (is_file(order_path($id))) $id = 'RDM-' . date('ymd') . '-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
$o = [
    'id' => $id, 'key' => bin2hex(random_bytes(8)), 'created_at' => date('c'), 'status' => 'new', 'type' => 'cert',
    'lines' => [['code' => 'giftcard', 'name' => 'Электронный подарочный сертификат RUE DE MIRÓ', 'qty' => 1, 'price' => $amount * 100, 'amount' => $amount * 100, 'object' => (int)$c['cert']['payment_object']]],
    'items' => [], 'full' => $amount, 'sum' => $amount, 'total' => $amount, 'to_pay' => $amount, 'delivery' => ['price' => 0, 'title' => 'по электронной почте'],
    'cert' => ['design' => $design, 'to_name' => $to, 'to_email' => $toEmail, 'from' => $from, 'note' => $note, 'send_date' => $date],
    'customer' => ['name' => $from ?: $buyer, 'phone' => '', 'email' => $buyer, 'telegram' => ''], 'comment' => '',
    'consent' => ['offer' => true, 'pd' => true, 'at' => date('c'), 'ip' => $ip, 'ua' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200)],
];
order_save($o);
$hits[] = time(); file_put_contents($rl, json_encode(array_values($hits)));
if (!alfa_url()) {
    $o['status'] = 'accepted'; order_save($o);
    mail_send($c['shop_email'], "Сертификат {$id} — ждёт оплаты (онлайн-оплата выключена)", "Сумма: " . rub($amount) . "\nПокупатель: $buyer\nКому: $to <$toEmail>\n");
    out(['ok' => true, 'order' => $id, 'key' => $o['key'], 'payUrl' => null]);
}
$back = rtrim($c['site'], '/') . '/thanks?order=' . $id . '&k=' . $o['key'];
$params = ['orderNumber' => $id, 'amount' => $amount * 100, 'currency' => 643, 'language' => 'ru', 'returnUrl' => $back, 'failUrl' => $back . '&fail=1',
           'description' => 'Подарочный сертификат ' . $id, 'email' => $buyer];
if ($c['alfa']['send_bundle']) { $params['orderBundle'] = json_encode(alfa_bundle($o), JSON_UNESCAPED_UNICODE); $params['taxSystem'] = $c['alfa']['tax_system']; }
$r = alfa_call('register.do', $params);
if (!$r || empty($r['formUrl'])) {
    $o['status'] = 'error'; $o['alfa_error'] = $r['errorMessage'] ?? 'no response'; order_save($o);
    out(['ok' => false, 'error' => 'Не удалось открыть оплату. Попробуйте ещё раз через минуту или напишите нам на salut@ruedemiro.com.']);
}
$o['alfa'] = ['orderId' => $r['orderId'], 'mode' => $c['alfa']['mode']]; order_save($o);
out(['ok' => true, 'order' => $id, 'key' => $o['key'], 'payUrl' => $r['formUrl']]);
