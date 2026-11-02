<?php
// Проверка кода в корзине: промокод на первый заказ или подарочный сертификат (ничего не списывает)
require __DIR__ . '/lib.php';
$ip = $_SERVER['REMOTE_ADDR'] ?? '0';
$rl = orders_dir() . '/.prate_' . md5($ip) . '.json';
$hits = is_file($rl) ? array_filter(json_decode((string)file_get_contents($rl), true) ?: [], fn($t) => $t > time() - 3600) : [];
if (count($hits) >= 30) out(['ok' => false, 'error' => 'Слишком много попыток. Попробуйте позже.'], 429);
$hits[] = time(); file_put_contents($rl, json_encode(array_values($hits)));
$x = code_lookup((string)($_GET['code'] ?? ''));
if (!$x) out(['ok' => false, 'error' => 'Код не найден. Проверьте, нет ли опечатки.']);
if (!empty($x['error'])) out(['ok' => false, 'error' => $x['error']]);
out(['ok' => true] + $x);
