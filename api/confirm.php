<?php
// Подписка: шаг 2 — подтверждение адреса и выдача персонального кода на первый заказ
require __DIR__ . '/lib.php';
$t = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
if (strlen($t) !== 32) sub_page('Ссылка не работает', '<p>Проверьте ссылку из письма или подпишитесь на сайте ещё раз.</p>');
$c = cfg();
$r = subs_edit(function (array &$all) use ($t, $c) {
    foreach ($all as $em => $s) {
        if (($s['token'] ?? '') !== $t) continue;
        $fresh = empty($s['confirmed_at']);
        if ($fresh) {
            $s['confirmed_at'] = date('c');
            $s['code'] = 'MIRO-' . strtoupper(substr(str_replace(['0', 'O', '1', 'I'], '', bin2hex(random_bytes(8))), 0, 6));
            $s['code_until'] = date('Y-m-d', time() + 86400 * $c['welcome']['days']);
            $s['code_used'] = null;
        }
        $s['unsub_at'] = null;
        $all[$em] = $s;
        return ['s' => $s, 'fresh' => $fresh];
    }
    return null;
});
if (!$r) sub_page('Ссылка не работает', '<p>Возможно, ссылка устарела. Подпишитесь на сайте ещё раз.</p>');
$s = $r['s']; $until = date('d.m.Y', strtotime($s['code_until']));
$unsub = $c['site'] . '/api/unsubscribe.php?t=' . $s['token'];
if ($r['fresh']) {
    $pc = $c['welcome']['percent']; $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
    $text = "Спасибо, адрес подтверждён.\n\nВаш код: {$s['code']}\nСкидка {$pc}% на первый заказ на сайте, действует до $until.\n"
        . "Введите его в поле «Сертификат или промокод» в корзине. Код не суммируется со скидкой за количество флаконов — применяется бóльшая.\n\n"
        . "Раз в месяц будем присылать письмо о бьюти-рутине.\nОтписаться: $unsub\n\nRUE DE MIRÓ\n{$c['site']}";
    $html = mail_tpl([
        'hero' => 'hero-welcome.jpg', 'preheader' => "Ваш код −{$pc}% на первый заказ: {$s['code']}",
        'kicker' => 'ДОБРО ПОЖАЛОВАТЬ', 'title' => 'Спасибо, что вы с нами',
        'html' => '<p style="margin:0 0 14px">Адрес подтверждён. Теперь раз в месяц мы будем присылать письмо о бьюти-рутине: как наносить масла и сочетать формулы, о новинках и закрытых предложениях для подписчиков.</p>'
            . '<p style="margin:0">RUE DE MIRÓ — пять масел для тела с ароматом: Core, Balance, Escape, Recharge и Weightless. Чтобы знакомство было приятным, дарим скидку на первый заказ.</p>',
        'box' => '<p style="margin:0 0 6px;font-family:\'IBM Plex Mono\',\'Courier New\',monospace;font-size:11px;letter-spacing:2px;color:#4A4846">ВАШ КОД НА ПЕРВЫЙ ЗАКАЗ</p>'
            . '<p style="margin:0 0 10px;font-family:Forum,Georgia,serif;font-size:44px;line-height:1.1;color:#1B1A19">−' . $pc . '%</p>'
            . '<p style="margin:0 0 14px"><span style="display:inline-block;padding:10px 18px;border:1px solid #1B1A19;background:#FFFFFF;font-family:\'IBM Plex Mono\',\'Courier New\',monospace;font-size:20px;letter-spacing:4px;color:#1B1A19">' . $e($s['code']) . '</span></p>'
            . '<p style="margin:0">Действует до ' . $until . '. Введите код в корзине в поле «Сертификат или промокод».<br>Не суммируется со скидкой за количество флаконов — применится бóльшая.</p>',
        'button' => ['Выбрать масло', $c['site'] . '/#catalog'],
        'after' => '<p style="margin:0;text-align:center">Не знаете, с чего начать? <a href="' . $c['site'] . '/quiz" style="color:#1B1A19">Подберите формулу за минуту →</a></p>',
        'unsub' => $unsub,
    ]);
    mail_html($s['email'], "Ваш код −{$pc}% на первый заказ", $html, $text);
}
sub_page('Адрес подтверждён', '<p>Ваш код на первый заказ — скидка ' . $c['welcome']['percent'] . '% до ' . $until . '. Мы продублировали его на почту.</p><div class="code">' . htmlspecialchars($s['code']) . '</div><p class="m">ВВЕДИТЕ КОД В КОРЗИНЕ В ПОЛЕ «СЕРТИФИКАТ ИЛИ ПРОМОКОД»</p>');
