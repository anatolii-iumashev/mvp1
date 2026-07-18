Ниже — цельное решение тестового задания на **Laravel 13 + Filament 5**, рассчитанное на **production под параллельными воркерами**, с идемпотентностью, ретраями и админкой.

Стек и конвенции в этом репозитории: Laravel как backend-основа, Filament как админ-контур.

---

## 1) Ключевые требования и допущения

**Требования:**

- один входящий звонок должен получить **не более одного** назначения оператора;
- несколько воркеров обрабатывают очередь параллельно;
- внешняя телефония не гарантирует exactly-once → делаем идемпотентность у себя;
- при ошибках — ретраи, при исчерпании — управляемый `failed`.

**Базовый выбор архитектуры:**

**DB-транзакция + row locks** для назначения + **Outbox** для внешнего HTTP.

---

## 2) Модель данных (миграции/индексы)

### Таблица `calls`

Поля (пример):

- `id`
- `phone`
- `status` (`new`, `assigned`, `dispatched`, `failed`)
- `client_id` nullable
- `operator_id` nullable
- `assigned_at` nullable
- `dispatched_at` nullable
- `attempts_assign` int default 0
- `last_error` text nullable

Индексы:

- `index(status, created_at)`
- `index(phone)` (или уникальный/нормализованный для клиента отдельно)

### Таблица `clients`

- `phone` **unique** (нормализованный E.164 желательно)

### Таблица `operators`

Поля:

- `id`
- `available` bool
- `last_call_at` datetime nullable
- (опционально) `active` bool, `capacity`, `skills`, etc.

Индексы:

- `index(available, last_call_at)`

### Таблица `outbox_events`

Поля:

- `id`
- `type` (например `call.assigned`)
- `aggregate_type` (например `call`)
- `aggregate_id` (call_id)
- `payload_json`
- `status` (`pending`, `sent`, `failed`)
- `attempts` int default 0
- `next_retry_at` datetime nullable
- `last_error` text nullable
- timestamps

Критично:

- **уникальный индекс** на `(type, aggregate_type, aggregate_id)`
чтобы один `call_id` не породил 2 одинаковых события при гонках.

---

## 3) Очереди и Jobs (разделяем ответственность)

### Очереди

- `calls_assign` — высокоприоритетная (назначение оператора)
- `telephony_dispatch` — низкоприоритетная (внешние HTTP)

---

## 4) Job #1: назначение оператора (конкурентно-безопасно)

### `ProcessIncomingCallJob`

Идея: **в транзакции** залочить звонок и выбранного оператора.

Псевдокод (важные моменты):

```php
public function handle(): void
{
    DB::transaction(function () {
        $call = Call::query()
            ->whereKey($this->callId)
            ->lockForUpdate()
            ->first();

        if (!$call) return;

        // идемпотентность
        if (!in_array($call->status, ['new'])) {
            return;
        }

        // клиент (лучше с нормализацией телефона заранее)
        $clientId = Client::query()
            ->where('phone', $call->phone)
            ->value('id');

        // выбираем оператора конкурентно
        // Postgres/MySQL8: FOR UPDATE SKIP LOCKED
        $operator = Operator::query()
            ->where('available', true)
            ->orderByRaw('COALESCE(last_call_at, "1970-01-01") asc')
            ->lockForUpdate()
            ->skipLocked()
            ->first();

        if (!$operator) {
            // это ожидаемая ситуация, можно ретраить через backoff
            throw new NoAvailableOperatorException();
        }

        $operator->available = false;
        $operator->last_call_at = now();
        $operator->save();

        $call->client_id = $clientId;
        $call->operator_id = $operator->id;
        $call->status = 'assigned';
        $call->assigned_at = now();
        $call->save();

        OutboxEvent::createUnique(
            type: 'call.assigned',
            aggregateType: 'call',
            aggregateId: $call->id,
            payload: [
                'call_id' => $call->id,
                'operator_id' => $operator->id,
            ],
        );
    });
}
```

**Важно:**

- никакого HTTP внутри транзакции;
- статус `new → assigned` делаем только один раз под `FOR UPDATE`;
- создание outbox события защищено уникальным индексом (на всякий случай).

### Ретраи/бэкофф

- Для `NoAvailableOperatorException`: backoff (например 1s, 2s, 5s, 10s…) + jitter.
- Для прочих DB-ошибок: стандартные ретраи Laravel.

---

## 5) Job #2: доставка в телефонию (идемпотентно)

### `DispatchOutboxEventJob`

Логика:

1. выбрать `pending` события, у которых `next_retry_at <= now()` (пачкой);
2. залочить запись события (`FOR UPDATE SKIP LOCKED`), чтобы несколько воркеров не отправили одно и то же;
3. вызвать внешнюю телефонию;
4. при успехе: `sent`, обновить `calls.status = dispatched`, `dispatched_at`;
5. при 5xx/timeout: увеличить attempts, выставить `next_retry_at` (экспонента + jitter);
6. при 4xx (логическая ошибка): `failed` и алерт/ручной разбор.

**Идемпотентность во внешней системе:**

- если API поддерживает `Idempotency-Key` — передавать `call:{id}:assigned`;
- если нет — всё равно защищаемся тем, что outbox событие единственное и повторная отправка контролируема.

---

## 6) Освобождение оператора и завершение звонка

Назначение — это только старт. Нужно определить, когда оператор снова `available=true`.
Варианты:

- webhook/коллбек от телефонии “call ended” → отдельный job: выставляет оператору `available=true`;
- если webhook нет — периодический poll/cron (хуже);
- ручное управление из админки Filament (fallback).

---

## 7) Filament админка (минимальный, но полезный набор)

### 7.1 CallsResource

Таблица:

- `id`, `phone`, `status`, `client`, `operator`, `assigned_at`, `dispatched_at`, `attempts_assign`, `last_error`

Фильтры:

- status (new/assigned/dispatched/failed)
- time ranges
- operator

Actions (с аудитом):

- **Retry dispatch** (создать/перевести outbox в pending)
- **Unassign** (если бизнес позволяет): снять `operator_id`, сделать оператора available=true, вернуть `status=new` (или отдельный `needs_review`)
- **Mark failed** / **Reset to new**

### 7.2 OperatorsResource

Поля:

- `available`, `last_call_at`, (опц) `active`

Actions:

- Toggle available (с reason)
- “Force release” если оператор завис

### 7.3 OutboxEventsResource

Таблица:

- type, aggregate_id (call_id), status, attempts, next_retry_at, last_error, created_at

Actions:

- Retry now
- Mark failed
- View payload

**Доступ:**

- отдельный guard/домен, ограничение по IP/VPN, роли.

---

## 8) Наблюдаемость (минимум для production)

- структурные логи с `call_id`, `operator_id`, `outbox_event_id`
- метрики:
    - время `new → assigned`
    - время `assigned → dispatched`
    - глубина очередей
    - % ретраев outbox
    - число `failed` событий
- алерты: рост `failed`, рост `pending` старше N минут

---

## 9) Тесты: что добавить первыми

1. **Integration DB concurrency**: два воркера одновременно → не назначают одного оператора двум звонкам.
2. **Idempotency**: повторный запуск `ProcessIncomingCallJob` для `assigned/dispatched` ничего не меняет.
3. **Outbox uniqueness**: при гонке создаётся одно событие.
4. **Dispatch retry policy**: 5xx → pending + backoff, 4xx → failed.
5. **Filament actions**: retry/force release корректно меняют состояние.

---

## 10) Что НЕ делать прямо сейчас

- Kafka/сложный event bus (outbox + очереди достаточно).
- сложный routing операторов (skills/очереди/приоритеты) без требований.
- premature sharding БД.

---

Если хочешь, я могу адаптировать это решение под конкретную БД (Postgres vs MySQL 8) и показать точные Eloquent-запросы с `SKIP LOCKED` (они немного отличаются по драйверу).

# notes

Спасибо за интерес к вакансии —  здорово, что ты здесь!

Мы подготовили небольшое тестовое задание — оно поможет нам лучше познакомиться с тем, как ты работаешь с базовыми принципами тестирования. Обычно оно занимает час.

Срок выполнения — **2-3 дня с момента получения задания.**

Прежде чем мы перейдем к заданию, хотим уточнить один важный момент: мы - международная компания не имеющая юр. лиц в рамках РФ. Поэтому мы предлагаем следующие **форматы оплаты:**

**1.** Оплата в USDT (у нас есть партнеры во многих городах, которые могут помочь с вопросами выводов);

**2.** Платеж на карту в рублях

***Пожалуйста, приступай к выполнению этого задания только в том случае, если тебя это устраивает.***

**После выполнения задания** — пиши напрямую в Telegram: **@Xenia_hr_GT**, можно просто представиться, написав фамилию и имя (или направить резюме), написать название позиции и **прикрепить ссылку на выполненное тестовое задание**, проверив, что есть доступ.

После этого нас будет ждать еще 2 этапа коммуникации: короткий созвон с HR, где я подробнее расскажу про позицию, и техническое интервью с руководителем.

Удачи — будем рады познакомиться поближе!

# Тестовое задание

Входящий звонок создаёт запись в таблице [calls], после чего в очередь Redis отправляется ProcessIncomingCallJob.

Job должен:

- найти клиента по номеру телефона;
- выбрать доступного оператора;
- назначить звонок оператору;
- отправить событие в телефонию;
- записать лог;
- при ошибке повториться.

Система работает в production под нагрузкой. Обработка звонков выполняется несколькими воркерами параллельно.

### **Фрагмент кода**

class ProcessIncomingCallJob implements ShouldQueue

{

public $tries = 5;

private $callId;

public function __construct($callId)

{

$this->callId = $callId;

}

public function handle()

{

$call = Call::find($this->callId);

if (!$call) {

return;

}

if ($call->status === 'new') {

$client = Client::where('phone', $call->phone)->first();

if ($client) {

$call->client_id = $client->id;

}

$operator = Operator::where('available', true)

->orderBy('last_call_at')

->first();

if (!$operator) {

throw new \Exception('No available operators');

}

$operator->available = false;

$operator->save();

$call->operator_id = $operator->id;

$call->status = 'assigned';

$call->save();

// HTTP-запрос во внешнюю телефонию для назначения звонка оператору.

// Гарантии внешней системы неизвестны.

app(TelephonyClient::class)->sendCallAssigned($call->id, $operator->id);

Log::info('Call assigned', [

'call_id' => $call->id,

'operator_id' => $operator->id,

]);

}

}

}

### **Задачи**

1. **Найдите 7–10 проблем в решении.**
2. **Предложите варианты исправлений.**
3. **Разделите проблемы по критичности:**
    
    Критические / важные / было бы хорошо сделать
    
4. **Опишите, какие тесты вы бы добавили первыми.**
5. **Что вы бы не стали делать прямо сейчас?**

Если поведение внешней системы, очереди, телефонии или legacy-кода не описано явно, укажите свои предположения. Отдельно опишите риски и опасения, которые возникают из-за этой неопределённости.

### **Вопрос про масштабирование**

Представьте, что через полгода нагрузка выросла в 10–50 раз: больше входящих звонков, больше операторов, больше параллельных workers, больше событий во внешнюю телефонию.

Опишите план масштабирования решения.

Нужно раскрыть:

- какие bottleneck-и вы ожидаете в текущей реализации;
- что даст простое увеличение количества workers и где оно перестанет помогать;
- какие лимиты могут возникнуть в Redis, БД, HTTP-интеграции с телефонией и логировании;

---

## RFC: Надёжная обработка входящих звонков (Laravel Queue) + админка на Filament

### 0) Контекст и цель

Сейчас `ProcessIncomingCallJob` назначает оператора и дергает внешнюю телефонию. Под нагрузкой и при нескольких воркерах возникают гонки, дубли, неконсистентность и трудно дебажить.

**Цель RFC**: описать варианты решения (Laravel + инфраструктура) и выбрать базовый дизайн, который:

- гарантирует *не более одного* назначения звонка оператору (или как минимум идемпотентность);
- корректно обрабатывает ретраи и сбои внешней телефонии;
- масштабируется при росте нагрузки;
- даёт прозрачную операционную картину (метрики/логи/трейсинг);
- предоставляет админ-интерфейс (Filament) для мониторинга и ручных операций.

### 1) Предположения (явно)

1. `calls` создаётся синхронно при входящем звонке. Поля: `id, phone, status, client_id, operator_id, created_at, updated_at`.
2. Внешняя телефония **может** принимать повторные запросы и не гарантирует exactly-once. Мы должны строить идемпотентность на своей стороне.
3. Redis используется как драйвер очередей + может использоваться для локов/ratelimit.
4. База данных — транзакционная (MySQL/Postgres).

### 2) Опции реализации (Laravel)

#### Вариант A — DB-транзакция + row-level locking (рекомендуемый базовый)

**Идея**: назначение оператора делаем в транзакции с блокировками строк (SELECT … FOR UPDATE) и переводом статуса.

- Блокируем строку звонка (`calls`) и строку выбранного оператора (`operators`) в одной транзакции.
- Выбор оператора делаем так, чтобы два воркера не могли взять одного и того же: `SELECT … FOR UPDATE SKIP LOCKED` (Postgres/MySQL 8) или эквивалент.
- После коммита отправляем событие во внешнюю телефонию отдельным шагом (см. outbox ниже).

Плюсы:

- Чёткие гарантии консистентности в рамках БД.
- Не зависит от Redis-локов.

Минусы:

- Упирается в БД при очень высокой конкуренции.

#### Вариант B — Redis lock (advisory lock) + упрощённые DB-апдейты

**Идея**: на `call:{id}` берём `SETNX`-лок с TTL, один воркер назначает.

Плюсы:

- Дешевле по БД на пике.

Минусы:

- Риски из-за TTL (долго работающий job, GC-паузы, сетевые проблемы) → возможно двойное назначение.
- Требует аккуратной продлёнки TTL и обработки "зависших" локов.

#### Вариант C — Разделение на этапы (Saga) + Outbox (event-driven)

**Идея**: один job отвечает только за консистентное назначение в БД; отправка во внешнюю систему делается отдельным job’ом из таблицы outbox.

Плюсы:

- Самая предсказуемая доставка в внешнюю систему.
- Можно масштабировать отдельно этапы.

Минусы:

- Больше сущностей/таблиц/кода.

**Рекомендация**: взять **A + элементы C (outbox)** как оптимальный баланс.

### 3) Предлагаемый дизайн (A + Outbox)

#### 3.1 Состояния звонка

Ввести явные статусы:

- `new` — создан, не обработан
- `assigning` — идёт назначение (опционально)
- `assigned` — оператор назначен в нашей БД
- `dispatching` — отправляем во внешнюю телефонию (опционально)
- `dispatched` — внешняя телефония подтверждена
- `failed` — исчерпаны попытки / ручное вмешательство

Минимум: `new → assigned → dispatched` (+ `failed`).

#### 3.2 Идемпотентность

- Любой job должен начинаться с проверки статуса: если уже `assigned/dispatched` — выход.
- Для внешнего вызова использовать **idempotency key** (если поддерживается) или хранить факт отправки у нас:
    - таблица `telephony_requests` или `outbox` с уникальным ключом `call_id + event_type`.

#### 3.3 Транзакционное назначение оператора

Псевдокод:

1. `DB::transaction()`
2. `call = calls where id=? for update`
3. если `call.status != 'new'` → return
4. найти клиента (можно до транзакции, но запись `client_id` — внутри транзакции)
5. выбрать оператора:
    - `operators where available=true order by last_call_at for update skip locked limit 1`
6. обновить оператора: `available=false`, `last_call_at=now()`
7. обновить звонок: `operator_id`, `client_id`, `status='assigned'`
8. создать outbox event: `CallAssigned(call_id, operator_id)`
9. commit

**Важно**: никаких HTTP-вызовов внутри транзакции.

#### 3.4 Outbox и доставка во внешнюю телефонию

Таблица `outbox_events`:

- `id, aggregate_type, aggregate_id, type, payload_json, status(pending|sent|failed), attempts, next_retry_at, created_at`
- уникальный индекс (например): `(type, aggregate_id)` для защиты от дублей.

Job `DispatchOutboxEventJob`:

- берёт пачку `pending` с `next_retry_at <= now()`
- делает HTTP вызов
- при успехе → `sent`
- при сетевой/5xx → увеличивает `attempts`, выставляет `next_retry_at` (exponential backoff + jitter)
- при 4xx (валидация/логика) → `failed` и алерт

#### 3.5 Логирование и наблюдаемость

- Корреляция: `call_id` в каждом логе + request id.
- Метрики: время назначения, глубина очереди, процент ретраев, time-to-dispatch, доля `failed`.
- Алёрты: рост `failed`, рост `pending` в outbox, рост latency.

### 4) Где тут Filament и какие варианты

Filament используем для внутренней админки/операций:

**Экран 1: Calls**

- фильтры по статусам, времени, оператору
- кнопки: "переотправить в телефонию", "снять назначение", "пометить как failed" (под RBAC)

**Экран 2: Operators**

- текущая доступность, last_call_at
- ручной toggle available (с аудитом)

**Экран 3: Outbox/Telephony events**

- список событий, attempts, last_error
- retry/force-send

Варианты внедрения Filament:

1. **Filament как отдельный admin panel** в том же Laravel приложении (быстро, дёшево).
2. Filament + отдельный домен/guard + ограничение по IP/VPN.
3. Если админка тяжёлая — выделять отдельно позже; сейчас не нужно.

### 5) Масштабирование (рост 10–50×)

#### 5.1 Ожидаемые bottleneck’и сейчас

- Гонки при выборе оператора (без блокировок) → двойные назначения.
- Внешний HTTP внутри job без идемпотентности → дубли.
- БД: частые `where available=true order by last_call_at` без индексов.
- Логи: объём и стоимость записи синхронно.

#### 5.2 Что даст увеличение workers и где перестанет помогать

- До определённого момента увеличит throughput.
- Дальше упрётся в:
    - конкуренцию за блокировки в БД (если операторов мало относительно звонков);
    - лимиты Redis (ack/visibility, memory, network);
    - лимиты внешней телефонии (rate limit, QPS).

#### 5.3 Конкретные меры

- **БД**:
    - индексы: `calls(status, created_at)`, `clients(phone) unique`, `operators(available, last_call_at)`
    - `SKIP LOCKED` для конкурентного потребления операторов
    - шардинг/реплики читать позже (не сразу)
- **Redis/Queue**:
    - отдельные очереди: `calls_assign`, `telephony_dispatch`
    - приоритеты: назначение выше, диспетч ниже
    - rate limiting на диспетч (чтобы не DDOS телефонию)
- **HTTP телефония**:
    - идемпотентность по ключу
    - circuit breaker + fallback в `failed` с алертом
- **Логи**:
    - структурные логи + sampling для успешных путей
    - отдельный канал для ошибок/алертов

### 6) Тесты (что добавить первыми)

1. **Unit**: выбор оператора (справедливость `orderBy(last_call_at)`), переходы статусов.
2. **Integration (DB)**: два параллельных воркера не назначают одного оператора двум звонкам.
3. **Integration (job)**: повторный запуск job при `assigned/dispatched` не меняет данные.
4. **Contract tests** для TelephonyClient (что считаем успехом/ошибкой).
5. **Outbox retry**: 5xx → ретрай, 4xx → failed.

### 7) Что не делать прямо сейчас

- Не внедрять Kafka/сложный event bus, пока outbox + очереди покрывают объём.
- Не строить сложный график справедливости распределения операторов (skill-based routing и т.п.) без требований.
- Не оптимизировать до premature sharding БД.

### 8) Открытые вопросы

- Поддерживает ли телефония idempotency-key / дедупликацию?
- Нужно ли учитывать навыки/языки/очереди операторов?
- SLA по времени назначения: сколько секунд допустимо?
- Что делать при падении телефонии: удерживать звонок в assigned и переотправлять или переводить в отдельный статус?
