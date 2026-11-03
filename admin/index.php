<?php
// Просмотр заказов, сертификатов и подписчиков RUE DE MIRÓ. Только чтение.
// Доступ — по логину и паролю (admin/.htaccess, файл паролей вне папки сайта).
declare(strict_types=1);
date_default_timezone_set('Europe/Moscow');

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

// если сервер почему-то не спросил пароль — не показываем ничего
$user = $_SERVER['REMOTE_USER'] ?? $_SERVER['REDIRECT_REMOTE_USER'] ?? $_SERVER['PHP_AUTH_USER'] ?? '';
if ($user === '') { http_response_code(403); exit('Доступ закрыт'); }

$dir = dirname(__DIR__) . '/api/orders';
function jread(string $f): array { $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null; return is_array($d) ? $d : []; }
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function rub($v): string { return is_numeric($v) ? number_format((float)$v, 0, ',', ' ') . ' ₽' : ''; }
function dt($v): string { if ($v === null || $v === '') return ''; $t = is_numeric($v) ? (int)$v : strtotime((string)$v); return $t ? date('d.m.Y H:i', $t) : (string)$v; }
function d($v): string { if (!$v) return ''; $t = strtotime((string)$v); return $t ? date('d.m.Y', $t) : (string)$v; }

$ORDER_ST = ['new' => 'ждёт оплаты', 'accepted' => 'принят, без онлайн-оплаты', 'paid' => 'оплачен', 'failed' => 'оплата не прошла', 'error' => 'ошибка банка'];

// --- заказы
$orders = [];
foreach (glob($dir . '/RDM-*.json') ?: [] as $f) {
    $o = jread($f); if (!$o) continue;
    $dl = $o['delivery'] ?? []; $cu = $o['customer'] ?? [];
    $where = trim(($dl['city']['name'] ?? '') . ', ' . ($dl['pvz']['address'] ?? ($dl['pvzManual'] ?? '') ?: ($dl['address'] ?? '')), ', ');
    if (!empty($dl['pvz']['code'])) $where .= ' (' . $dl['pvz']['code'] . ')';
    $lines = [];
    foreach ($o['lines'] ?? [] as $l) $lines[] = ($l['name'] ?? $l['code'] ?? '') . ' × ' . ($l['qty'] ?? 1);
    $disc = [];
    if (!empty($o['promo']['code'])) $disc[] = 'промокод ' . $o['promo']['code'];
    if (!empty($o['cert_used']['code'])) $disc[] = 'сертификат ' . $o['cert_used']['code'] . ' (' . rub($o['cert_used']['amount'] ?? 0) . ')';
    $orders[] = [
        'created' => $o['created_at'] ?? '', 'Дата' => dt($o['created_at'] ?? ''), 'Номер' => $o['id'] ?? basename($f, '.json'),
        'Тип' => ($o['type'] ?? '') === 'cert' ? 'сертификат' : 'товары',
        'Статус' => $ORDER_ST[$o['status'] ?? ''] ?? ($o['status'] ?? ''), '_st' => $o['status'] ?? '',
        'Оплачен' => dt($o['paid_at'] ?? ''),
        'Итого' => rub($o['total'] ?? 0), 'К оплате картой' => rub($o['to_pay'] ?? ($o['total'] ?? 0)),
        'Покупатель' => $cu['name'] ?? '', 'Телефон' => $cu['phone'] ?? '', 'Почта' => $cu['email'] ?? '', 'Telegram' => $cu['telegram'] ?? '',
        'Доставка' => trim(($dl['title'] ?? '') . ($where !== '' ? ': ' . $where : '')), 'Цена доставки' => isset($dl['price']) ? rub($dl['price']) : '',
        'Состав' => implode('; ', $lines), 'Скидки' => implode('; ', $disc), 'Комментарий' => $o['comment'] ?? '',
    ];
}
usort($orders, function ($a, $b) { return strcmp($b['created'], $a['created']); });

// --- сертификаты
$CERT_ST = ['active' => 'действует', 'used' => 'израсходован', 'expired' => 'истёк', 'blocked' => 'заблокирован'];
$certs = [];
foreach (jread($dir . '/certs.json') as $k => $x) {
    $hist = [];
    foreach ($x['history'] ?? [] as $h) if (is_array($h)) $hist[] = trim(dt($h['at'] ?? '') . ' ' . ($h['order'] ?? '') . ' ' . (isset($h['amount']) ? '−' . rub($h['amount']) : ''));
    $certs[] = [
        'Код' => $x['code'] ?? $k, 'Статус' => $CERT_ST[$x['status'] ?? ''] ?? ($x['status'] ?? ''),
        'Номинал' => rub($x['amount'] ?? 0), 'Остаток' => rub($x['balance'] ?? 0), 'Действует до' => d($x['until'] ?? ''),
        'Кому' => $x['to_name'] ?? '', 'Почта получателя' => $x['to_email'] ?? '', 'От кого' => $x['from'] ?? '', 'Почта покупателя' => $x['buyer_email'] ?? '',
        'Дата отправки' => d($x['send_date'] ?? ''), 'Отправлен' => dt($x['sent_at'] ?? ''), 'Заказ' => $x['order'] ?? '',
        'Пожелание' => $x['note'] ?? '', 'Списания' => implode('; ', $hist), '_until' => $x['until'] ?? '',
    ];
}
usort($certs, function ($a, $b) { return strcmp($b['_until'], $a['_until']); });

// --- подписчики
$subs = [];
foreach (jread($dir . '/subscribers.json') as $em => $s) {
    $st = !empty($s['unsub_at']) ? 'отписался' : (!empty($s['confirmed_at']) ? 'подтверждён' : 'не подтвердил адрес');
    $subs[] = [
        'Почта' => $s['email'] ?? $em, 'Имя' => $s['name'] ?? '', 'Статус' => $st, 'Подписался' => dt($s['consent_at'] ?? ($s['created_at'] ?? '')),
        'Подтвердил' => dt($s['confirmed_at'] ?? ''), 'Код −10%' => $s['code'] ?? '', 'Код до' => d($s['code_until'] ?? ''),
        'Код использован' => is_array($s['code_used'] ?? null) ? trim(($s['code_used']['order'] ?? '') . ' ' . dt($s['code_used']['at'] ?? '')) : (string)($s['code_used'] ?? ''),
        'Отписался' => dt($s['unsub_at'] ?? ''), 'Страница' => $s['page'] ?? '', 'created' => $s['created_at'] ?? '',
    ];
}
usort($subs, function ($a, $b) { return strcmp($b['created'], $a['created']); });

// --- вкладка, поиск, фильтр
$tabs = ['orders' => ['Заказы', $orders], 'certs' => ['Сертификаты', $certs], 'subs' => ['Подписчики', $subs]];
$t = isset($tabs[$_GET['t'] ?? '']) ? $_GET['t'] : 'orders';
$q = trim((string)($_GET['q'] ?? ''));
$only = (string)($_GET['st'] ?? '');
$rows = $tabs[$t][1];
if ($t === 'orders' && isset($ORDER_ST[$only])) $rows = array_values(array_filter($rows, function ($r) use ($only) { return $r['_st'] === $only; }));
if ($q !== '') {
    $ql = mb_strtolower($q); $qd = preg_replace('/\D/', '', $q);
    $rows = array_values(array_filter($rows, function ($r) use ($ql, $qd) {
        $hay = mb_strtolower(implode(' ', $r));
        return mb_strpos($hay, $ql) !== false || (strlen($qd) >= 4 && strpos(preg_replace('/\D/', '', $hay), $qd) !== false);
    }));
}
$cols = array_values(array_filter(array_keys($rows[0] ?? $tabs[$t][1][0] ?? []), function ($c) { return $c[0] !== '_' && $c !== 'created'; }));

// --- выгрузка для Excel: CSV в UTF-8 с BOM, разделитель «;»
if (!empty($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ruedemiro-' . $t . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $cols, ';');
    foreach ($rows as $r) {
        $line = [];
        foreach ($cols as $c) { $v = (string)($r[$c] ?? ''); if ($v !== '' && strpos('=+-@', $v[0]) !== false) $v = "'" . $v; $line[] = $v; }
        fputcsv($out, $line, ';');
    }
    exit;
}

$paid = array_filter($orders, function ($o) { return $o['_st'] === 'paid'; });
$paidSum = 0; foreach (glob($dir . '/RDM-*.json') ?: [] as $f) { $o = jread($f); if (($o['status'] ?? '') === 'paid') $paidSum += (int)($o['total'] ?? 0); }
$link = function (array $p) use ($t, $q, $only) { return '?' . http_build_query(array_filter(array_merge(['t' => $t, 'q' => $q, 'st' => $only], $p), function ($v) { return $v !== '' && $v !== null; })); };
?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>Заказы — RUE DE MIRÓ</title>
<style>
:root{--ink:#1B1A19;--muted:#4A4846;--line:#D6D2CB;--bg:#F6F4F0;--card:#FFFFFF}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.5 -apple-system,'Helvetica Neue',Arial,sans-serif}
header{padding:20px 24px 0}h1{margin:0 0 4px;font-size:20px;font-weight:500;letter-spacing:.04em}.sub{color:var(--muted);font-size:13px}
nav{display:flex;gap:8px;flex-wrap:wrap;padding:16px 24px}nav a{padding:8px 14px;border:1px solid var(--line);color:var(--ink);text-decoration:none;background:transparent}nav a.on{background:var(--card);border:2px solid var(--ink);padding:7px 13px}
form{display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:0 24px 12px}input[type=search]{flex:1;min-width:200px;min-height:40px;padding:0 12px;border:1px solid var(--line);font:inherit;background:var(--card)}
button,.btn{min-height:40px;padding:0 16px;border:1px solid var(--ink);background:var(--ink);color:#fff;font:inherit;font-size:12px;letter-spacing:.12em;text-transform:uppercase;text-decoration:none;display:inline-flex;align-items:center;cursor:pointer}.btn.o{background:transparent;color:var(--ink)}
.chips{display:flex;gap:6px;flex-wrap:wrap;padding:0 24px 12px}.chips a{font-size:12px;padding:4px 10px;border:1px solid var(--line);color:var(--ink);text-decoration:none}.chips a.on{border-color:var(--ink);background:var(--card)}
.wrap{margin:0 24px 24px;overflow:auto;border:1px solid var(--line);background:var(--card);max-height:calc(100vh - 230px)}
table{border-collapse:collapse;min-width:100%}th,td{padding:8px 10px;border-bottom:1px solid #ECE8E1;text-align:left;vertical-align:top}
th{position:sticky;top:0;background:var(--card);font-size:11px;font-weight:500;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);white-space:nowrap;border-bottom:1px solid var(--line)}
td{max-width:340px}td.n{white-space:nowrap}.st-paid{font-weight:600}.st-failed,.st-error{color:#9A2B2B}.empty{padding:24px;color:var(--muted)}
@media (max-width:600px){header,nav,form,.chips{padding-left:16px;padding-right:16px}.wrap{margin:0 16px 16px;max-height:none}}
</style></head><body>
<header><h1>RUE DE MIRÓ — заказы</h1>
<div class="sub">Заказов: <?= count($orders) ?> · оплачено: <?= count($paid) ?> на <?= rub($paidSum) ?> · сертификатов: <?= count($certs) ?> · подписчиков: <?= count($subs) ?> · только просмотр</div></header>
<nav><?php foreach ($tabs as $k => $v): ?><a href="?t=<?= $k ?>" class="<?= $k === $t ? 'on' : '' ?>"><?= e($v[0]) ?> · <?= count($v[1]) ?></a><?php endforeach; ?></nav>
<form method="get"><input type="hidden" name="t" value="<?= e($t) ?>"><?php if ($only !== ''): ?><input type="hidden" name="st" value="<?= e($only) ?>"><?php endif; ?>
<input type="search" name="q" value="<?= e($q) ?>" placeholder="Поиск: имя, телефон, почта, номер, код" aria-label="Поиск">
<button type="submit">Найти</button><?php if ($q !== ''): ?><a class="btn o" href="<?= e($link(['q' => ''])) ?>">Сбросить</a><?php endif; ?>
<a class="btn o" href="<?= e($link(['csv' => 1])) ?>">Скачать для Excel</a></form>
<?php if ($t === 'orders'): ?><div class="chips"><a href="<?= e($link(['st' => ''])) ?>" class="<?= $only === '' ? 'on' : '' ?>">все</a><?php foreach ($ORDER_ST as $k => $v): ?><a href="<?= e($link(['st' => $k])) ?>" class="<?= $only === $k ? 'on' : '' ?>"><?= e($v) ?></a><?php endforeach; ?></div><?php endif; ?>
<div class="wrap"><?php if (!$rows): ?><div class="empty"><?= $q !== '' || $only !== '' ? 'Ничего не найдено.' : 'Пока пусто.' ?></div><?php else: ?>
<table><thead><tr><?php foreach ($cols as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><?php foreach ($cols as $c): $v = (string)($r[$c] ?? ''); ?><td class="<?= in_array($c, ['Дата', 'Номер', 'Итого', 'К оплате картой', 'Телефон', 'Код', 'Номинал', 'Остаток', 'Оплачен', 'Отправлен', 'Подписался', 'Подтвердил'], true) ? 'n' : '' ?><?= $c === 'Статус' && isset($r['_st']) ? ' st-' . e($r['_st']) : '' ?>"><?php
  if ($c === 'Почта' || $c === 'Почта получателя' || $c === 'Почта покупателя') echo $v !== '' ? '<a href="mailto:' . e($v) . '">' . e($v) . '</a>' : '';
  elseif ($c === 'Телефон') echo $v !== '' ? '<a href="tel:' . e(preg_replace('/[^\d+]/', '', $v)) . '">' . e($v) . '</a>' : '';
  else echo e($v); ?></td><?php endforeach; ?></tr>
<?php endforeach; ?></tbody></table><?php endif; ?></div>
</body></html>
