<?php
// Запуск по расписанию (раз в час): отправка сертификатов, назначенных на сегодня.
// В панели хостинга: php ~/test.ruedemiro.com/docs/api/cron.php   (путь к папке сайта может отличаться)
// Из браузера не запускается.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/lib.php';
// php cron.php resend GC-XXXX-XXXX — отправить сертификат повторно (получателю и копию покупателю)
// php cron.php test you@example.com — проверить отправку писем
if (($argv[1] ?? '') === 'resend' && !empty($argv[2])) { $x = cert_get(strtoupper($argv[2])); echo $x ? (cert_mail($x) ? "sent\n" : "FAILED, см. orders/mail.log\n") : "not found\n"; exit; }
if (($argv[1] ?? '') === 'test' && !empty($argv[2])) { echo mail_send($argv[2], 'Проверка почты RUE DE MIRÓ', "Если вы видите это письмо — отправка работает.\n") ? "sent\n" : "FAILED, см. orders/mail.log\n"; exit; }
certs_due();
echo "ok\n";
