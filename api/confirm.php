<?php
// Подписка: шаг 2 — подтверждение адреса и выдача персонального кода на первый заказ
require __DIR__ . '/lib.php';
$t = preg_replace('/[^a-f0-9]/', '', (string)($_POST['t'] ?? $_GET['t'] ?? ''));
if (strlen($t) !== 32) sub_page('Ссылка не работает', '<p>Проверьте ссылку из письма или подпишитесь на сайте ещё раз.</p>');
// переход по ссылке только показывает кнопку: почтовые антивирусы открывают ссылки сами, но кнопку не нажимают
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $known = false;
    foreach ((json_decode((string)@file_get_contents(subs_path()), true) ?: []) as $x) if (($x['token'] ?? '') === $t) { $known = true; break; }
    if (!$known) sub_page('Ссылка не работает', '<p>Возможно, ссылка устарела. Подпишитесь на сайте ещё раз.</p>');
    sub_page('Подтвердите адрес', '<p>Нажмите кнопку, чтобы подтвердить подписку на письма RUE DE MIRÓ. Сразу после этого покажем ваш код на первый заказ и продублируем его на почту.</p>'
        . '<form method="post" action="/api/confirm.php" style="margin:0"><input type="hidden" name="t" value="' . $t . '">'
        . '<button type="submit" style="min-height:48px;padding:0 24px;background:#1B1A19;color:#fff;border:0;font:500 12px Montserrat,\'Helvetica Neue\',Arial,sans-serif;letter-spacing:.18em;text-transform:uppercase;cursor:pointer">Подтвердить адрес</button></form>');
}
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
        . "Введите его в поле «Сертификат или промокод» в корзине. Скидка действует на все товары и подарочные наборы, кроме сертификатов.\n\n"
        . "Раз в месяц будем присылать письмо о бьюти-рутине.\nОтписаться: $unsub\n\nRUE DE MIRÓ\n{$c['site']}";
    $html = mail_tpl([
        'hero' => 'hero-welcome.jpg', 'preheader' => "Ваш код −{$pc}% на первый заказ: {$s['code']}",
        'kicker' => 'ДОБРО ПОЖАЛОВАТЬ', 'title' => 'Спасибо, что вы с нами',
        'html' => '<p style="margin:0 0 14px">Адрес подтверждён. Теперь раз в месяц мы будем присылать письмо о бьюти-рутине: как наносить масла и сочетать формулы, о новинках и закрытых предложениях для подписчиков.</p>'
            . '<p style="margin:0">RUE DE MIRÓ — пять формул сухого масла для тела: CORE, RECHARGE, ESCAPE, WEIGHTLESS и BALANCE. Аромат у них один, а формулы разные — для разных состояний кожи. Кожа на разных зонах тела ведёт себя по-разному и меняется со временем: в холод, в стресс, после отпуска. Поэтому масло подбирают под то, что нужно коже сейчас, и формулы можно сочетать. Чтобы знакомство было приятным, дарим скидку на первый заказ.</p>',
        'box' => '<p style="margin:0 0 6px;font-family:\'IBM Plex Mono\',\'Courier New\',monospace;font-size:11px;letter-spacing:2px;color:#4A4846">ВАШ КОД НА ПЕРВЫЙ ЗАКАЗ</p>'
            . '<p style="margin:0 0 10px;font-family:Forum,Georgia,serif;font-size:44px;line-height:1.1;color:#1B1A19">−' . $pc . '%</p>'
            . '<p style="margin:0 0 14px"><span style="display:inline-block;padding:10px 18px;border:1px solid #1B1A19;background:#FFFFFF;font-family:\'IBM Plex Mono\',\'Courier New\',monospace;font-size:20px;letter-spacing:4px;color:#1B1A19">' . $e($s['code']) . '</span></p>'
            . '<p style="margin:0">Действует до ' . $until . '. Введите код в корзине в поле «Сертификат или промокод».<br>Скидка действует на все товары и подарочные наборы, кроме сертификатов.</p>',
        'button' => ['Выбрать масло', $c['site'] . '/#catalog'],
        'after' => '<p style="margin:0;text-align:center">Не знаете, с чего начать? <a href="' . $c['site'] . '/quiz" style="color:#1B1A19">Подберите формулу за минуту →</a></p>',
        'unsub' => $unsub,
    ]);
    mail_html($s['email'], "Ваш код −{$pc}% на первый заказ", $html, $text);
}
sub_page('Адрес подтверждён', '<p>Ваш код на первый заказ — скидка ' . $c['welcome']['percent'] . '% до ' . $until . '. Мы продублировали его на почту.</p><div class="code">' . htmlspecialchars($s['code']) . '</div><p class="m">ВВЕДИТЕ КОД В КОРЗИНЕ В ПОЛЕ «СЕРТИФИКАТ ИЛИ ПРОМОКОД»</p>');
