# nbcom/laravel-sso-auth

SSO-авторизация через ЕЦА NBCom для Laravel 12+.

Пакет даёт:

- готовые маршруты `GET /sso/login` и `GET /sso/callback`;
- Blade/HTML-кнопку, которая подключает боевой JS-виджет прямо с хаба;
- проверку подписи, `state` и срока ответа;
- DTO профиля — пакет **не логинит пользователя сам** и ничего не пишет в вашу БД;
- клиент делегированного входа `POST /api/sso/delegate` для сценария «сайт А логинит на сайт Б».

## Требования

- PHP 8.2+
- Laravel 12+
- Ваш сайт и callback-URL доступны по **HTTPS** — хаб отклоняет `http`.
- Домен(ы) `return_url` и `target_url` должны быть в `allowed_return_domains` /
  `allowed_target_domains` вашего клиента на хабе.

## Установка

### Из GitHub (публичный репозиторий)

```bash
composer config repositories.nbcom-sso vcs https://github.com/SmoKE585/NBCom-AuthHub-Laravel-Client.git
composer require nbcom/laravel-sso-auth
```

Либо добавьте в `composer.json` приложения:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/SmoKE585/NBCom-AuthHub-Laravel-Client.git"
        }
    ]
}
```

Провайдер подключается автоматически (`extra.laravel.providers`).

### Публикация конфига

```bash
php artisan vendor:publish --tag=sso-auth-config
```

При необходимости можно переопределить Blade-шаблон кнопки:

```bash
php artisan vendor:publish --tag=sso-auth-views
```

## Настройка

Добавьте в `.env`:

```dotenv
SSO_AUTH_CLIENT_ID=your-client-id
SSO_AUTH_CLIENT_SECRET=your-client-secret
SSO_AUTH_BASE_URL=https://sso.example.com

# Куда вернуть пользователя после успешного входа (можно относительный путь)
SSO_AUTH_REDIRECT_AFTER_CALLBACK=/dashboard

# Куда вернуть при ошибке (необязательно)
SSO_AUTH_ERROR_REDIRECT=/login
```

`SSO_AUTH_BASE_URL` обязателен: в пакете нет адреса хаба по умолчанию.
Секрет хранится только на сервере и никогда не попадает в браузер.

Полный список параметров — в `config/sso-auth.php`. Чаще всего нужны:

| Параметр | Назначение |
|---|---|
| `base_url` | Адрес хаба |
| `client_id`, `client_secret` | Учётные данные клиента |
| `ttl_seconds` | Окно валидности ответа (по умолчанию 180 с) |
| `routes.prefix` | Префикс маршрутов (по умолчанию `sso`) |
| `routes.redirect_after_callback` | Redirect после успешного callback |
| `allowed_target_hosts` | Домены, куда разрешён redirect (по умолчанию — хост приложения) |
| `button.js_url` | URL JS-виджета на хабе |
| `delegate.endpoint` | Путь API делегирования |

## Кнопка

Вставьте в любой Blade-шаблон:

```blade
{!! \Nbcom\SsoAuth\LoginButton::render() !!}
```

Пакет отрисует контейнер `.ssonbcom-auth-button` с готовым `/start`-URL и один
раз подключит скрипт с хаба:

```html
<div class="ssonbcom-auth-button" data-url="https://sso.example.com/start?..."></div>
<script src="https://sso.example.com/ssonbcom-auth-button.js?v=1730000000" defer></script>
```

`?v=<unix time>` добавляется всегда, чтобы обойти кеш браузера и nginx. Сам
скрипт в пакет не копируется — он живёт на хабе и обновляется там.

Параметры рендера:

```blade
{{-- Вернуть пользователя на конкретную страницу --}}
{!! \Nbcom\SsoAuth\LoginButton::render(targetUrl: route('dashboard')) !!}

{{-- Дополнительные data-атрибуты и CSS-класс --}}
{!! \Nbcom\SsoAuth\LoginButton::render(
    attributes: ['data-theme' => 'light'],
    class: 'mx-auto max-w-sm',
) !!}
```

> `{!! \Nbcom\SsoAuth\LoginButton !!}` без `::render()` в Blade не сработает:
> PHP попытается вывести класс как константу. Используйте `::render()`.

Если JS-кнопка не нужна (например, вход только редиректом), ведите пользователя на:

```php
return redirect()->route('sso-auth.login');
```

## Что происходит после входа

1. Кнопка или `/sso/login` уводит браузер на `/start` хаба.
2. Хаб логинит пользователя в Bitrix24 и возвращает на `/sso/callback`
   подписанный набор `state`, `bitrix_user_id`, `ts`, `user`, `sig`.
3. Пакет проверяет `state` (одноразовый, хранится в сессии), срок `ts`
   (180 с + допуск часов) и HMAC-подпись.
4. Публикуется событие `Nbcom\SsoAuth\Events\SsoCallbackReceived` с DTO.
5. DTO кладётся в сессию под ключом `sso-auth.callback` (можно отключить).
6. Браузер редиректится на сохранённый `target_url` или
   `routes.redirect_after_callback`.

Автоматического `Auth::login()` нет.

### Вариант 1: слушатель события

```php
use Illuminate\Support\Facades\Auth;
use Nbcom\SsoAuth\Events\SsoCallbackReceived;

// В AppServiceProvider::boot() или отдельном провайдере
Event::listen(function (SsoCallbackReceived $event) {
    $ssoUser = $event->result->user; // Nbcom\SsoAuth\Dto\SsoUser

    $user = \App\Models\User::query()
        ->where('bitrix_id', $ssoUser->bitrixId)
        ->where('is_active', true)
        ->first();

    if ($user === null) {
        abort(403, 'Доступ запрещён.');
    }

    Auth::login($user);
});
```

### Вариант 2: забрать DTO после редиректа

```php
$data = session('sso-auth.callback');

if ($data !== null) {
    // $data['bitrix_id'], $data['name'], $data['avatar'], ...
}
```

### Вариант 3: свой контроллер

Отключите маршруты пакета и вызовите сервис вручную:

```dotenv
SSO_AUTH_ROUTES_ENABLED=false
SSO_AUTH_CALLBACK_URL=https://example.com/auth/sso/callback
```

```php
use Nbcom\SsoAuth\Facades\SsoAuth;

public function callback(Request $request)
{
    $result = SsoAuth::validateCallback($request);

    $user = User::where('bitrix_id', $result->user->bitrixId)->firstOrFail();
    Auth::login($user);

    return redirect()->to($result->targetUrl ?? '/dashboard');
}
```

### DTO `SsoUser`

```php
final readonly class SsoUser
{
    public int $bitrixId;
    public string $name;
    public ?string $firstName;
    public ?string $lastName;
    public ?string $workPosition;
    public ?string $avatar;
    public ?string $gender;
    public ?string $birthday;
    public array $departments;
    public array $departmentsName;
    public array $raw;

    public function toArray(): array;
    public function displayName(): string;
}
```

`birthday` и `gender` приходят от Bitrix24. Если они вам не нужны — не
сохраняйте их. Помните, что подписанный профиль передаётся в query-строке и
может попасть в логи.

## Делегированный вход

Сценарий: пользователь уже авторизован на сайте А, и сайт А хочет открыть ему
сессию на сайте Б без повторного ввода пароля.

```php
use Nbcom\SsoAuth\Facades\SsoAuth;

$state = Str::random(32);
session(['delegate_state' => $state]);

$url = SsoAuth::delegate()->redirectUrl(
    targetClientId: 'site_b_client_id',
    bitrixUserId: $currentUser->bitrix_id,
    redirectUrl: 'https://site-b.example.com/sso/callback',
    state: $state,                                          // необязательно
    targetUrl: 'https://site-b.example.com/dashboard',      // необязательно
);

return redirect()->away($url);
```

Метод выполняет backend-to-backend запрос к хабу и возвращает готовый
подписанный URL. `client_secret` сайта А при этом остаётся на сервере.
`targetUrl` добавляется в `redirect_url` как query-параметр — сайт Б вернёт
пользователя туда после callback (домен проверяется на стороне сайта Б).

Сайт Б принимает этот URL своим обычным callback-маршрутом (маршруты пакета
подходят). При делегировании сайт Б не инициировал вход и не хранит `state`,
поэтому пакет по умолчанию принимает такой callback без записи в сессии:

- проверяются подпись и срок `ts`;
- подпись `sig` помечается в cache как использованная на 210 секунд
  (`Cache::add`), повторный вход по тому же URL отклоняется.

Отключить это поведение, если делегирование не используется:

```dotenv
SSO_AUTH_DELEGATE_STATELESS=false
```

> Stateless-callback неизбежно слабее обычного: `state` в нём не участвует.
> Защита строится на HMAC-подписи, коротком TTL и одноразовости `sig`.
> Если у вас есть свой backend-канал между сайтами, передавайте `state`
> и проверяйте его вручную через `SsoAuth::validateCallback()`.

При ошибке бросается `Nbcom\SsoAuth\Exceptions\DelegateException` с сообщением
хаба (401 — неверные креды, 422 — невалидные параметры, 429 — rate-limit).

## Маршруты

| Метод | Путь | Имя | Назначение |
|---|---|---|---|
| GET | `/sso/login` | `sso-auth.login` | Редирект на хаб |
| GET | `/sso/callback` | `sso-auth.callback` | Приём подписанного ответа |

Префикс и middleware меняются в `config/sso-auth.php` → `routes`.

## Безопасность

- `state` генерируется на сервере, хранится в сессии и изымается одноразово.
- Подпись проверяется через `hash_equals` (timing-safe).
- `ts` ограничен окном 180 секунд + 30 секунд допуска.
- После callback домен `target_url` повторно проверяется (`allowed_target_hosts`),
  чтобы приложение не стало open redirect.
- `client_secret` используется только на сервере.
- Пакет не выполняет `Auth::login()` и не пишет в БД.

## Ограничения и частые проблемы

- **Локальная разработка по HTTP не работает**: хаб принимает только `https`
  return/target URL. Для отладки нужен туннель с https или стенд хаба.
- **Домены должны быть в allowlist клиента** на хабе, иначе `/start` вернёт
  `forbidden_return_url` / `forbidden_target_url`.
- **Страница с кнопкой должна обновляться**: тикет живёт 180 секунд. Если
  страница закеширована дольше, виджет покажет «Токен просрочен» — это штатно.
- **JS всегда грузится с хаба** и привязан к `base_url`. Если вы поднимаете
  отдельный стенд хаба, задайте `SSO_AUTH_JS_URL` на его виджет.
- **Персональная кнопка «Войти как Иванов И.»** работает только для сайтов на
  том же домене верхнего уровня, что и хаб (same-site): браузер должен
  отправлять cookie хаба в кросс-доменном credentialed-запросе. Для внешних
  доменов виджет покажет обычную кнопку.

## Лицензия

MIT.
