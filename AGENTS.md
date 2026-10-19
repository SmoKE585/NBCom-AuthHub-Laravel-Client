# AGENTS.md — карта пакета `nbcom/laravel-sso-auth`

Пакет — **клиентская сторона** SSO-хаба ЕЦА NBCom. Он ставится в
Laravel-приложение (сайт-клиент), а не в сам хаб. Адрес хаба задаётся
приложением через `SSO_AUTH_BASE_URL`; в пакете он не хранится.

Главный принцип: пакет **проверяет ответ хаба и отдаёт DTO**, но не логинит
пользователя, не создаёт его и не пишет в БД. Решение остаётся приложению.

## Поток

```
Браузер
  │  <div class="ssonbcom-auth-button" data-url=".../start?...">
  │  <script src="{SSO_AUTH_BASE_URL}/ssonbcom-auth-button.js?v=<time()>">
  ▼
JS-виджет с хаба (iframe-модалка)
  │  GET {SSO_AUTH_BASE_URL}/start?client_id&ticket&state&return_url&target_url[&frame=1]
  ▼
Хаб: логин в Bitrix24 → подписанный redirect
  │  GET {return_url}?state&bitrix_user_id&ts&user&sig
  ▼
GET /sso/callback (этот пакет)
  │  SsoClient::validateCallback()
  ├─ state из сессии, одноразово
  ├─ ts в окне 180 с + 30 с skew
  ├─ HMAC-SHA256 подпись
  └─ base64url(JSON) → SsoUser DTO
  ▼
Событие SsoCallbackReceived + session('sso-auth.callback')
  ▼
redirect на target_url или routes.redirect_after_callback
```

Fallback без JS: `GET /sso/login` сразу редиректит на `/start` хаба.

Делегирование (`SsoAuth::delegate()->redirectUrl(...)`) — отдельный
backend-to-backend сценарий: сайт А с известным `bitrix_user_id` просит хаб
выдать подписанный URL для сайта Б. Браузер в обмене не участвует.

## Структура

```
config/sso-auth.php                  конфиг (env-ключи SSO_AUTH_*)
routes/sso-auth.php                  GET login, GET callback
resources/views/button.blade.php     контейнер кнопки + <script> с хаба
src/
  Protocol.php                       константы протокола, TTL 180
  SsoClient.php                      createLoginUrl() + validateCallback()
  SsoAuth.php                        фасад-сервис приложения
  LoginButton.php                    {!! LoginButton::render() !!}
  SsoAuthServiceProvider.php         singleton-регистрация, маршруты, views
  Facades/SsoAuth.php                фасад
  Dto/SsoUser.php                    профиль пользователя
  Dto/SsoCallbackResult.php          DTO + target_url + state + ts
  Events/SsoCallbackReceived.php     событие после успешной проверки
  Exceptions/                        SsoAuthException, InvalidCallbackException, DelegateException
  Http/Controllers/LoginController.php
  Http/Controllers/CallbackController.php
  Services/DelegateClient.php        POST /api/sso/delegate
  Support/StateStore.php             одноразовые state в сессии
  Support/UrlGuard.php               защита от open redirect
```

## Контракты, которые нельзя ломать

1. **Параметры `/start`**: `client_id`, `ticket`, `state`, `return_url`,
   `target_url`, опционально `frame=1` и `quick`.
2. **Тикет**: `base64url(JSON payload) . "." . base64url(HMAC-SHA256(payloadB64, secret))`.
   Payload: `client_id`, `state`, `exp`, `nonce`, `return_url`, `target_url`.
3. **Ответ хаба**: `state`, `bitrix_user_id`, `ts`, `user`, `sig`.
4. **Строка подписи — строго в этом порядке и с этими разделителями**:
   `client_id={client_id}&state={state}&bitrix_user_id={id}&ts={ts}`.
5. **`user`** — `base64url(JSON)` с `bitrix_id`, `name`, `firstName`, `lastName`,
   `workPosition`, `avatar`, `gender`, `birthday`, `departments`, `departments_name`.
6. **TTL**: 180 секунд (фактический TTL хаба), допуск часов — 30 секунд.
7. **`return_url` и `target_url`** — только `https`, домен должен быть в
   allowlist клиента на хабе.
8. **`state`** — одноразовый, хранится в сессии и изымается через `pull()`.

Любое изменение этих пунктов требует синхронной правки в репозитории хаба.

## Инварианты реализации

- **JS-виджет не копируется в пакет.** `LoginButton` подключает
  `button.js_url` с хаба и всегда добавляет `?v=time()` (кеш-бастинг).
  Виджет на хабе — источник истины.
- **Пакет не вызывает `Auth::login()`.** Только событие + DTO в сессии.
- **`client_secret` не попадает в HTML, JS, URL и логи.** Только сервер.
- **Маршруты пакета — в группе `web`**, иначе не работает сессия для `state`.
- **`UrlGuard`** проверяет `target_url` после callback, чтобы приложение не
  стало open redirect. По умолчанию разрешён только хост `app.url`.
- **`LoginButton::$scriptIncluded`** — static-флаг на PHP-запрос: несколько
  кнопок на странице подключают скрипт один раз.

## Конфигурация

Все ключи — в `config/sso-auth.php`, env-префикс `SSO_AUTH_`.

| Env | По умолчанию | Смысл |
|---|---|---|
| `SSO_AUTH_BASE_URL` | — (обязателен) | Адрес хаба |
| `SSO_AUTH_CLIENT_ID` | — | client_id клиента |
| `SSO_AUTH_CLIENT_SECRET` | — | секрет, только сервер |
| `SSO_AUTH_TTL` | `180` | окно валидности ответа |
| `SSO_AUTH_CLOCK_SKEW` | `30` | допуск часов |
| `SSO_AUTH_ROUTES_ENABLED` | `true` | регистрировать маршруты |
| `SSO_AUTH_ROUTE_PREFIX` | `sso` | префикс `/sso` |
| `SSO_AUTH_REDIRECT_AFTER_CALLBACK` | `/` | куда вернуть после входа |
| `SSO_AUTH_ERROR_REDIRECT` | `/` | куда вернуть при ошибке |
| `SSO_AUTH_CALLBACK_URL` | — | явный return_url |
| `SSO_AUTH_JS_URL` | `{base}/ssonbcom-auth-button.js` | скрипт виджета |
| `SSO_AUTH_JS_CACHE_BUST` | `true` | `?v=time()` |
| `SSO_AUTH_STORE_CALLBACK` | `true` | класть DTO в сессию |
| `SSO_AUTH_DELEGATE_ENDPOINT` | `/api/sso/delegate` | API делегирования |
| `SSO_AUTH_DELEGATE_TIMEOUT` | `10` | таймаут HTTP |
| `SSO_AUTH_DELEGATE_STATELESS` | `true` | принимать делегированный callback без state |

`allowed_target_hosts` задаётся только в конфиге (не через env). Пустой
массив = текущий хост приложения.

## Грабли

- **HTTP в разработке не работает.** Хаб принимает только https
  `return_url`/`target_url`. Нужен туннель или стенд.
- **Домены должны быть в allowlist хаба.** Иначе `/start` отвечает
  `forbidden_return_url` / `forbidden_target_url`.
- **Кеш JS.** Виджет у клиентов кешируется надолго; `?v=time()` — обязателен.
  Не заменяйте его на статичную версию.
- **Страница с кнопкой живёт 180 секунд.** Тикет в `data-url` протухает,
  виджет штатно показывает «Токен просрочен» и предлагает обновить страницу.
  Не кешируйте HTML с кнопкой.
- **`frame=1`.** Виджет сам добавляет его к `/start`. Серверная часть пакета
  этот параметр не формирует и не должна.
- **Персональная кнопка** («Войти как Иванов И.») работает только на сайтах,
  same-site с хабом: нужны cookie хаба в кросс-доменном `fetch` с `credentials`.
  Для внешних доменов API хаба возвращает `account: null`.
- **Callback может прийти без state** (сессия истекла, другая вкладка) —
  это `InvalidCallbackException`, пользователь уходит на `error_redirect`
  с флеш-ошибкой `sso`. Это ожидаемое поведение, не баг.
- **Делегированный callback идёт без state.** Хаб генерирует `Str::random(32)`,
  если сайт А не передал state, и у сайта Б этой записи в сессии нет. Поэтому
  при `delegate.allow_stateless_callback = true` пакет принимает callback без
  state, но проверяет подпись, TTL и одноразовость `sig` через `Cache::add`.
  Если выключить — делегированный вход работать не будет.
- **`target_url` при делегировании** передаётся в query `redirect_url`
  (сайт А → хаб → сайт Б). Он не подписан, поэтому `UrlGuard` проверяет домен
  перед редиректом.
- **Не логируйте query callback целиком**: в `user` едут ПДн (`birthday`,
  `gender`), а в `sig` — подпись.

## Как проверять изменения

В репозитории пакета:

```bash
php -l src/...                                  # синтаксис
composer validate --strict                      # composer.json
```

В тестовом Laravel-приложении, где пакет подключён через path-repository:

```bash
php artisan vendor:publish --tag=sso-auth-config
php artisan route:list | grep sso
php artisan view:cache && php artisan view:clear
```

Живой сценарий: открыть страницу с `{!! \Nbcom\SsoAuth\LoginButton::render() !!}`,
нажать кнопку, войти в Bitrix24/OTP, убедиться, что `/sso/callback` вернул
на `target_url`, а событие `SsoCallbackReceived` сработало.

## Публичный репозиторий

Пакет публичный. В нём **не должно быть**:

- реальных `client_id`/`client_secret` и любых `.env` с секретами;
- приватных доменов, токенов, URL стендов;
- логов с ПДн.

В примерах — только плейсхолдеры. Адрес хаба задаётся через
`SSO_AUTH_BASE_URL` и в репозиторий не коммитится.
