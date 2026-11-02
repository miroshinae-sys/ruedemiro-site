<?php
// Уведомление от Альфа-Банка об оплате (адрес указывается в личном кабинете банка):
// https://ruedemiro.com/api/callback.php
// Статус не берём из запроса на веру — перепроверяем его в банке.
require __DIR__ . '/lib.php';
$o = order_load((string)($_GET['orderNumber'] ?? $_POST['orderNumber'] ?? ''));
if ($o) {
    $md = (string)($_GET['mdOrder'] ?? $_POST['mdOrder'] ?? '');
    if (!empty($o['alfa']['orderId']) && hash_equals($o['alfa']['orderId'], $md)) order_refresh($o);
}
http_response_code(200);
echo 'OK';
