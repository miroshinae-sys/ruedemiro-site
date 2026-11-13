<?php
// Подписка на письма RUE DE MIRÓ в один шаг: сразу выдаём код на первый заказ и отправляем одно письмо с кодом.
require __DIR__ . '/lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'Метод не поддерживается'], 405);
$in = json_decode((string)file_get_contents('php://input', false, null, 0, 4000), true) ?: [];
$email = mb_strtolower(trim((string)($in['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120) out(['ok' => false, 'error' => 'Проверьте адрес почты.'], 400);
if (empty($in['consent']) || empty($in['consent_pd'])) out(['ok' => false, 'error' => 'Нужно согласие на обработку персональных данных и на получение писем.'], 400);
$name = mb_substr(trim(strip_tags((string)($in['name'] ?? ''))), 0, 40);
if (!preg_match('/\p{L}{2,}/u', $name)) $name = '';   // «1», «..» и т.п. — не имя
$ip = $_SERVER['REMOTE_ADDR'] ?? '0';

// защита от ботов: ловушка, слишком быстрое заполнение, gmail с 3+ точками.
// Боту отвечаем «ок» (чтобы не подбирал обход), но ничего не сохраняем и не отправляем.
$bot = '';
if (trim((string)($in['hp'] ?? '')) !== '') $bot = 'ловушка';
elseif ((int)($in['ms'] ?? 0) < 3000) $bot = 'быстро';
elseif (preg_match('/^([^@]+)@(gmail|googlemail)\.com$/', $email, $gm) && substr_count($gm[1], '.') >= 3) $bot = 'gmail-точки';
if ($bot !== '') {
    @file_put_contents(orders_dir() . '/subscribe-blocked.log', date('c') . "\t$bot\t" . substr((string)strrchr($email, '@'), 1) . "\t" . md5($ip) . "\n", FILE_APPEND);
    out(['ok' => true, 'state' => 'sent']);
}

// не больше 3 попыток с одного IP в час
$rl = orders_dir() . '/.srate_' . md5($ip) . '.json';
$hits = is_file($rl) ? array_filter(json_decode((string)file_get_contents($rl), true) ?: [], fn($t) => $t > time() - 3600) : [];
if (count($hits) >= 3) out(['ok' => false, 'error' => 'Слишком много попыток. Попробуйте позже.'], 429);
$hits[] = time(); file_put_contents($rl, json_encode(array_values($hits)));

$c = cfg();
$r = subs_edit(function (array &$all) use ($email, $ip, $in, $name, $c) {
    // неподтверждённые (старая двухшаговая схема) старше 7 дней — удаляем; считаем письма за последний час
    $sentHour = 0;
    foreach ($all as $k => $x) {
        $t = (int)($x['sent_at'] ?? 0) ?: (int)strtotime((string)($x['created_at'] ?? ''));
        if (empty($x['confirmed_at']) && $t && $t < time() - 7 * 86400) { unset($all[$k]); continue; }
        if ($t > time() - 3600) $sentHour++;
    }
    $s = $all[$email] ?? null;
    if ($s && !empty($s['confirmed_at']) && empty($s['unsub_at'])) return ['state' => 'active'];
    if ($sentHour >= 30) return ['state' => 'busy'];
    $page = mb_substr((string)($in['page'] ?? ''), 0, 120);
    $s = array_merge($s ?: [], [
        'email' => $email, 'token' => $s['token'] ?? bin2hex(random_bytes(16)), 'created_at' => $s['created_at'] ?? date('c'),
        'name' => $name !== '' ? $name : ($s['name'] ?? ''), 'consent_at' => date('c'), 'consent_ip' => $ip, 'consent_pd_at' => date('c'),
        'consent_text' => 'Согласие на обработку персональных данных (https://ruedemiro.com/consent) и на получение рекламных и информационных писем RUE DE MIRÓ — дано отметкой галочки в форме подписки на странице ' . ($page ?: '/') . '. Отписаться можно в любом письме.',
        'page' => $page, 'sent_at' => time(), 'unsub_at' => null, 'confirmed_at' => date('c'), 'optin' => 'single',
    ]);
    if (empty($s['code'])) {
        $s['code'] = 'MIRO-' . strtoupper(substr(str_replace(['0', 'O', '1', 'I'], '', bin2hex(random_bytes(8))), 0, 6));
        $s['code_until'] = date('Y-m-d', time() + 86400 * $c['welcome']['days']);
        $s['code_used'] = null;
    }
    $all[$email] = $s;
    return ['state' => 'new', 's' => $s];
});
if ($r['state'] === 'busy') out(['ok' => false, 'error' => 'Сейчас слишком много заявок. Попробуйте через час.'], 429);
if ($r['state'] === 'active') out(['ok' => true, 'state' => 'active']);
$s = $r['s'];
welcome_mail($s);
out(['ok' => true, 'state' => 'new', 'code' => $s['code'], 'percent' => (int)$c['welcome']['percent'], 'until' => date('d.m.Y', strtotime($s['code_until']))]);
