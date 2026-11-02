<?php
// Статус заказа для страницы «Спасибо»
require __DIR__ . '/lib.php';
certs_due();
$o = order_load((string)($_GET['order'] ?? ''));
if (!$o || !hash_equals($o['key'], (string)($_GET['k'] ?? ''))) out(['ok' => false, 'error' => 'notfound'], 404);
$o = order_refresh($o);
$st = $o['status'];
if ($st === 'new' && !empty($_GET['fail'])) $st = 'failed';
if ($st === 'new' || $st === 'error') $st = $st === 'error' ? 'failed' : 'pending';
$extra = ($o['type'] ?? '') === 'cert' ? ['kind' => 'cert', 'to_name' => $o['cert']['to_name'], 'to_email' => $o['cert']['to_email'], 'send_date' => $o['cert']['send_date']] : ['kind' => 'goods'];
out(['ok' => true, 'status' => $st, 'number' => $o['id'], 'total' => $o['to_pay'] ?? $o['total'], 'email' => $o['customer']['email']] + $extra);
