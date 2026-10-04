# RUE DE MIRÓ — сайт бренда

Статический сайт бренда RUE DE MIRÓ (scented body oil). Сборка не нужна: страницы выгружены из Claude Design и работают как есть в любом веб-сервере.

- Боевой адрес: https://ruedemiro.com (хостинг RU-CENTER / nic.ru)

## Структура

| Файл / папка | Что это |
|---|---|
| `index.html` | Главная страница |
| `assort.html` | Ассортимент |
| `core.html`, `recharge.html`, `escape.html`, `weightless.html`, `balance.html` | Карточки продуктов (по странице на продукт) |
| `quiz.html`, `about-us.html`, `where-to-buy.html`, `collab.html` | Остальные разделы |
| `js/dc-lite.js` | Маленький рантайм интерактивности страниц (`{{ … }}`, `sc-if`, `sc-for`) |
| `img/`, `vid/` | Фотографии, логотип, видео текстуры |
| `robots.txt`, `sitemap.xml` | Для поисковиков. Страницы ссылаются на чистые адреса (`/core`, не `core.html`), как и sitemap — их обслуживает правило в `.htaccess` на сервере; адреса с `.html` получают 301 на чистые |
| `.nojekyll` | Остался от GitHub Pages (сейчас выключен): На хостинг не заливается |
| `.github/workflows/deploy.yml` | Автодеплой на хостинг nic.ru |
| `.github/known_hosts` | Ключ SSH-сервера хостинга (проверяется при деплое) |
| `CLAUDE.md` | Инструкция для Claude Code: как обновлять и проверять сайт. На хостинг не заливается |

Имена файлов `*.html` и пути внутри них не меняйте: страницы ссылаются друг на друга по этим именам.

До 02.10.2026 сайт был собран из страниц `*.dc.html` (`Главная.dc.html`, `Product.dc.html?id=…`); старые адреса больше не работают — перенаправления решили не делать.

При открытии страниц в консоли браузера бывают 404 на адреса вида `{{ res.href }}` — это заготовки шаблона, которые браузер пытается загрузить до того, как скрипт подставит настоящие пути. На работу сайта не влияют.

## Как обновить сайт

1. Получить новую выгрузку из Claude Design (zip).
2. Распаковать её **через `ditto`**, а не `unzip` — `unzip` портит кириллические имена файлов:
   ```bash
   ditto -x -k "Complete website structure.zip" /tmp/rdm-new
   ```
3. Скопировать файлы поверх содержимого репозитория (`README.md`, `CLAUDE.md`, `.github/`, `.gitignore`, `.nojekyll` из выгрузки не приходят — их не трогать). `.htaccess` из выгрузки в репозиторий не кладётся: он ведётся на сервере вручную (см. «HTTPS»).
4. Закоммитить и отправить:
   ```bash
   git add -A && git commit -m "Обновление сайта" && git push
   ```

Дальше всё делает GitHub Actions (см. ниже). Ход деплоя: вкладка **Actions** репозитория.

## Автодеплой на nic.ru

Каждый push в `main` запускает `.github/workflows/deploy.yml`:

1. **Backup** — копирует текущий сайт с сервера в `~/backups/ruedemiro-<дата>-<коммит>` (хранятся 5 последних). Если папки сайта на хостинге нет, падает с понятной ошибкой.
2. **Upload** — `rsync --delete` заливает репозиторий в папку сайта. Не заливаются и не удаляются на сервере: `.git/`, `.github/`, `.gitignore`, `.nojekyll`, `README.md`, `CLAUDE.md`, а также `.htaccess` и `.well-known/` — они ведутся на сервере вручную.
3. **Check site** — скачивает ключевые страницы и скрипты прямо с IP хостинга (`HOSTING_IP`, через `curl --resolve`) и сверяет md5 с файлами из репозитория. Так проверка работает, даже если DNS домена смотрит в другое место. Если HTTPS недоступен, проверка идёт по http.

Запустить деплой вручную без коммита: Actions → Deploy to nic.ru → Run workflow.

### Секреты репозитория (Settings → Secrets and variables → Actions)

| Секрет | Что в нём |
|---|---|
| `SSH_PRIVATE_KEY` | Приватный ключ деплоя. Публичная часть добавлена в `~/.ssh/authorized_keys` на хостинге |
| `SFTP_HOST` | SSH-адрес хостинга (`ssh.<логин>.nichost.ru`) |
| `SFTP_USER` | Логин хостинга |
| `SFTP_REMOTE_PATH` | Папка сайта относительно домашней: `ruedemiro.com/docs` |

Если ключ деплоя придётся заменить: сгенерировать новый (`ssh-keygen -t ed25519`), добавить публичную часть на сервер, приватную — в секрет `SSH_PRIVATE_KEY`, старую строку удалить из `authorized_keys`.

Сервер nic.ru поддерживает только устаревший тип ключа хоста `ssh-rsa`, поэтому в настройках SSH workflow стоит `HostKeyAlgorithms +ssh-rsa`. Хостинг блокирует IP после серии неудачных входов по SSH.

## Хостинг, домен, DNS, почта

- **Хостинг:** отдельный аккаунт RU-CENTER (не тот, где surf.consulting). Папка сайта: `~/ruedemiro.com/docs`. Веб-сервер: `91.189.114.4`.
- **Домен** `ruedemiro.com` зарегистрирован в RU-CENTER, оплачен до 19.12.2026.
- **DNS:** услуга «DNS-хостинг» RU-CENTER на том же аккаунте. Серверы домена: `ns3-l2.nic.ru`, `ns4-l2.nic.ru`, `ns8-l2.nic.ru`, `ns4-cloud.nic.ru`, `ns8-cloud.nic.ru`. Записи правятся в панели RU-CENTER → DNS-хостинг → Управление DNS-зонами; после правки обязательно нажать **«Выгрузить зону»**. В значениях CNAME/MX ставить точку в конце (`ruedemiro.com.`), иначе панель допишет домен.
- **Почта** salut@ruedemiro.com — Яндекс 360 (admin.yandex.ru). Зона DNS в Яндекс 360 тоже существует, но не используется.

Записи в зоне:

| Имя | Тип | Значение |
|---|---|---|
| `@` | A | `91.189.114.4` |
| `www` | CNAME | `ruedemiro.com.` |
| `@` | MX | `10 mx.yandex.net.` |
| `mail._domainkey` | TXT | DKIM-ключ из Яндекс 360 |
| `@` | TXT | `v=spf1 redirect=_spf.yandex.net` |

Проверить ответ самих серверов nic.ru (домашний роутер или провайдер могут долго помнить старый ответ):
```bash
dig +short ruedemiro.com A @ns3-l2.nic.ru
dig +short ruedemiro.com MX @ns3-l2.nic.ru
```

## HTTPS (Let's Encrypt)

На хостинге установлен `~/.acme.sh` (без cron — на хостинге его нет), аккаунт Let's Encrypt зарегистрирован. Выпуск — когда домен уже указывает на `91.189.114.4`:

```bash
~/.acme.sh/acme.sh --issue -d ruedemiro.com -d www.ruedemiro.com -w ~/ruedemiro.com/docs
```

Текущий сертификат (RSA 2048) выпущен 02.10.2026 и действует до **31.12.2026**. Копии для панели лежат на сервере в `~/ssl` (`ruedemiro.com.crt`, `ruedemiro.com.pkcs8.key`, `ruedemiro.com.ca.crt`). Сертификат и ключ загружаются вручную в панели хостинга nic.ru (ключ — в формате PKCS#8). Сертификат действует 90 дней и сам не продлевается: продлить за 2–3 недели до окончания и загрузить в панель заново.

Продление (на сервере, по SSH):
```bash
~/.acme.sh/acme.sh --renew -d ruedemiro.com --force
d=~/.acme.sh/ruedemiro.com
cp $d/ruedemiro.com.cer ~/ssl/ruedemiro.com.crt
cp $d/ca.cer ~/ssl/ruedemiro.com.ca.crt
openssl pkcs8 -topk8 -nocrypt -in $d/ruedemiro.com.key -out ~/ssl/ruedemiro.com.pkcs8.key
chmod 600 ~/ssl/*
```
Затем скачать три файла из `~/ssl` и загрузить в панели: «Сайты» → ruedemiro.com → «SSL-сертификаты». Ключ после загрузки удалить с компьютера.

Редиректы http→https и www→без www делаются в `.htaccess` на сервере. HTTPS на nic.ru завершается на прокси, поэтому в условиях надо проверять `%{HTTP:X-Forwarded-Proto}`, а не `%{HTTPS}` (иначе бесконечный редирект).
