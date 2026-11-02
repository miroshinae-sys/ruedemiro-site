<?php
// Отписка от писем в один клик (ссылка в каждом письме)
require __DIR__ . '/lib.php';
$t = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
$ok = strlen($t) === 32 && subs_edit(function (array &$all) use ($t) {
    foreach ($all as $em => $s) if (($s['token'] ?? '') === $t) { $all[$em]['unsub_at'] = date('c'); return true; }
    return false;
});
sub_page($ok ? 'Вы отписались' : 'Ссылка не работает', $ok ? '<p>Больше не будем присылать письма. Если передумаете — подписка есть внизу каждой страницы сайта.</p>' : '<p>Напишите нам на salut@ruedemiro.com — отпишем вручную.</p>');
