<?php
// Подписка на «Письмо о бьюти-рутине»: шаг 1 — сохранить адрес и отправить письмо с подтверждением (double opt-in)
require __DIR__ . '/lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'Метод не поддерживается'], 405);
$in = json_decode((string)file_get_contents('php://input', false, null, 0, 4000), true) ?: [];
$email = mb_strtolower(trim((string)($in['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120) out(['ok' => false, 'error' => 'Проверьте адрес почты.'], 400);
if (empty($in['consent'])) out(['ok' => false, 'error' => 'Нужно согласие на получение писем.'], 400);

// не больше 5 попыток с одного IP в час
$ip = $_SERVER['REMOTE_ADDR'] ?? '0';
$rl = orders_dir() . '/.srate_' . md5($ip) . '.json';
$hits = is_file($rl) ? array_filter(json_decode((string)file_get_contents($rl), true) ?: [], fn($t) => $t > time() - 3600) : [];
if (count($hits) >= 5) out(['ok' => false, 'error' => 'Слишком много попыток. Попробуйте позже.'], 429);
$hits[] = time(); file_put_contents($rl, json_encode(array_values($hits)));

$c = cfg();
$r = subs_edit(function (array &$all) use ($email, $ip, $in) {
    $s = $all[$email] ?? null;
    if ($s && !empty($s['confirmed_at']) && empty($s['unsub_at'])) return ['state' => 'active', 's' => $s];
    if ($s && !empty($s['sent_at']) && $s['sent_at'] > time() - 600) return ['state' => 'wait', 's' => $s];
    $s = array_merge($s ?: [], [
        'email' => $email, 'token' => bin2hex(random_bytes(16)), 'created_at' => $s['created_at'] ?? date('c'),
        'consent_at' => date('c'), 'consent_ip' => $ip, 'consent_text' => 'Согласна получать рекламные и информационные письма RUE DE MIRÓ. Отписаться можно в любом письме.',
        'page' => mb_substr((string)($in['page'] ?? ''), 0, 120), 'sent_at' => time(), 'unsub_at' => null,
    ]);
    $all[$email] = $s;
    return ['state' => 'new', 's' => $s];
});
if ($r['state'] === 'new') {
    $link = $c['site'] . '/api/confirm.php?t=' . $r['s']['token'];
    $pc = $c['welcome']['percent'];
    $text = "Здравствуйте!\n\nВы подписались на «Письмо о бьюти-рутине» RUE DE MIRÓ. Подтвердите, что это ваш адрес:\n\n$link\n\n"
        . "После подтверждения пришлём код −{$pc}% на первый заказ на сайте.\n\nЕсли вы не подписывались, просто не отвечайте на это письмо — без подтверждения мы ничего не отправим.\n\nRUE DE MIRÓ\n{$c['site']}";
    $html = mail_tpl([
        'hero' => 'hero-confirm.jpg', 'preheader' => "Один клик — и код −{$pc}% на первый заказ придёт следом",
        'kicker' => 'ПИСЬМО О БЬЮТИ-РУТИНЕ', 'title' => 'Подтвердите адрес',
        'html' => '<p style="margin:0 0 14px">Здравствуйте!</p>'
            . '<p style="margin:0">Вы подписались на письма RUE DE MIRÓ — раз в месяц о бьюти-рутине, текстурах и ароматах, новых формулах и закрытых предложениях. Осталось подтвердить, что это ваш адрес.</p>',
        'button' => ['Подтвердить адрес', $link],
        'after' => '<p style="margin:0 0 12px">Сразу после подтверждения пришлём персональный код <b style="font-weight:500">−' . $pc . '% на первый заказ</b> на сайте.</p>'
            . '<p style="margin:0;font-size:13px;color:#8C8A87">Если вы не подписывались, просто проигнорируйте это письмо — без подтверждения мы ничего не отправим.</p>',
    ]);
    mail_html($email, "Подтвердите адрес — и код −{$pc}% придёт следом", $html, $text);
}
out(['ok' => true, 'state' => $r['state']]);
