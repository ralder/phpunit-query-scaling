Ты — senior PHP/Laravel engineer и maintainer production-grade open-source Composer packages.

Нужно спроектировать и затем разработать новый пакет:

Repository: phpunit-query-scaling
Composer package: <vendor>/phpunit-query-scaling
PHP namespace: <Vendor>\QueryScaling
License: MIT

Работай в текущем репозитории. Сначала изучи его состояние, composer.json, структуру, git history и уже имеющиеся файлы. Если репозиторий пустой, не начинай автоматически генерировать код до согласования дизайна.

# Контекст продукта

В PHP уже существуют:

- query counters;
- query budgets;
- N+1 detectors;
- поиск повторяющихся SQL fingerprints;
- исторические baseline между коммитами;
- EXPLAIN-анализаторы.

Этот пакет не должен дублировать их.

Уникальная задача phpunit-query-scaling:

Проверять, как количество SQL-запросов одного и того же сценария меняется при контролируемом увеличении количества тестовых данных.

Пакет должен запускать сценарий для нескольких scale factors, например:

- 2 объекта;
- 5 объектов;
- 10 объектов;

и проверять контракт роста количества запросов:

- количество запросов остаётся постоянным;
- количество запросов растёт не быстрее заданного slope;
- диагностически показывать, какие SQL fingerprints растут вместе с cardinality.

Это PHP/Laravel-аналог идеи Ruby n_plus_one_control, но не буквальный порт.

Важно: не утверждай, что несколько экспериментальных точек математически доказывают O(1) или O(N). Это эмпирическая проверка выбранных scale factors. В документации используй формулировки query scaling contract или controlled scaling assertion.

# Цель первой версии

Сделать небольшой, надёжный и хорошо документированный Laravel-first пакет для PHPUnit и Pest.

MVP должен позволять написать примерно такой тест:

$this->assertQueriesScaleConstantly(
    scales: [2, 5, 10],

    populate: function (int $scale): void {
        Post::factory()->count($scale)->create();
    },

    run: function (): void {
        $this->getJson('/api/posts')->assertSuccessful();
    },
);

Или через fluent API:

QueryScaling::for(
    run: fn () => $this->getJson('/api/posts')
)
    ->scales([2, 5, 10])
    ->populate(fn (int $scale) =>
        Post::factory()->count($scale)->create()
    )
    ->assertConstant();

Это только иллюстрации. До реализации сравни 2–3 варианта публичного API и предложи наиболее простой и устойчивый.

Для допустимого линейного роста нужен контракт примерно такого смысла:

$this->assertQueryScaling(
    scales: [2, 5, 10],
    populate: fn (int $scale) =>
        Job::factory()->count($scale)->create(),
    run: fn () =>
        app(JobProcessor::class)->process(),
    expectation: QueryGrowth::atMostSlope(1),
);

Семантика atMostSlope(1):

при увеличении cardinality на один элемент количество запросов не должно увеличиваться больше чем на один запрос, с опциональным абсолютным tolerance.

Для constant-контракта slope равен нулю.

# Основные требования MVP

## 1. Контролируемые scale runs

Для каждого scale factor пакет должен:

1. создать изолированное состояние;
2. вызвать populate($scale);
3. при необходимости выполнить необязательный warmup;
4. включить query collector;
5. вызвать run();
6. выключить collector;
7. сохранить query count и SQL fingerprints;
8. гарантированно очистить состояние даже при исключении;
9. перейти к следующему scale factor.

Запросы из populate, очистки данных и управления транзакциями не должны попадать в измерение run().

## 2. Изоляция данных

Это одна из самых важных частей дизайна.

Нужна стратегия изоляции для каждого scale run. Предпочтительный default для Laravel — отдельная вложенная транзакция/savepoint с rollback после измерения.

Необходимо учесть:

- тест уже может быть обёрнут Laravel DatabaseTransactions или RefreshDatabase;
- до запуска assertion может существовать ненулевой transaction level;
- callback может открыть собственные вложенные транзакции;
- callback может ошибочно сделать commit или изменить transaction depth;
- очистка обязана выполняться в finally;
- разные DB connections;
- SQLite, MySQL и PostgreSQL могут вести себя по-разному.

Зафиксируй transaction level перед scale run и после него возвращай connection к исходному уровню. Если callback разрушил ожидаемую структуру транзакций и безопасно восстановить её невозможно, assertion должен завершаться понятной ошибкой.

Предусмотри интерфейс пользовательской IsolationStrategy или reset callback для сценариев, где транзакционная изоляция неприменима.

Не пытайся в MVP автоматически откатывать Redis, filesystem, внешние API или queued side effects. Это должно быть явно отражено в документации.

## 3. Query collection

Laravel adapter должен использовать QueryExecuted / DB::listen() или другой официальный механизм Laravel.

Требования:

- listener не должен регистрироваться повторно при каждом assertion;
- listener может существовать постоянно, но записывать запросы только при активной measurement session;
- повторные assertions в одном процессе не должны смешивать результаты;
- при исключении collector всегда должен деактивироваться;
- по умолчанию измеряется default connection;
- должна быть возможность явно выбрать одно или несколько connections;
- connection name должен быть частью внутренней идентичности запроса;
- bindings не должны делать каждый запрос отдельным fingerprint;
- полезно хранить несколько примеров bindings для диагностического отчёта, но не выводить потенциально чувствительные значения без явного режима.

## 4. SQL fingerprints

Fingerprint нужен для объяснения причины роста.

Минимальная идентичность:

- connection name;
- нормализованный SQL template;
- без bindings.

Нормализация должна быть осторожной:

- привести whitespace к стабильному виду;
- не пытаться писать сложный SQL parser в MVP;
- не разрушать семантически важные части SQL;
- запросы Laravel с placeholders должны естественно группироваться;
- raw SQL с встроенными литералами можно поддержать ограниченно и честно задокументировать.

Для каждого fingerprint нужно хранить число появлений на каждом scale factor.

## 5. Scaling expectations

Для MVP реализовать только:

- ConstantQueryCount;
- AtMostSlopeQueryCount.

ConstantQueryCount:

- все измеренные counts должны быть равны;
- допускается configurable absolute tolerance;
- diagnostic slope можно вычислять, но он не должен заменять точную проверку контракта.

AtMostSlopeQueryCount:

- сравнивать рост query count относительно роста scale;
- не полагаться только на одну линейную regression;
- контракт должен проверять реальные пары или последовательные точки;
- slope и tolerance должны иметь однозначную документированную семантику.

Не добавлять в MVP автоматическую классификацию quadratic, logarithmic и других complexity classes.

## 6. Failure report

При падении assertion сообщение должно быть полезным без подключения debugger.

Пример:

Query scaling assertion failed

Expected:
  query count to remain constant

Measurements:

Scale       Queries
2           4
5           7
10          12

Observed diagnostic trend:
  approximately Q(n) = 2 + 1.0n

Growing query fingerprints:

1. select * from "users" where "id" = ? limit 1

Scale       Occurrences
2           2
5           5
10          10

Connection:
  mysql

Показывай в первую очередь fingerprints, количество которых выросло между scale factors.

Отчёт должен быть:

- детерминированным;
- хорошо читаемым в CI;
- без обязательной поддержки ANSI colors;
- пригодным для snapshot/unit testing.

Diagnostic trend не должен формулироваться как математически доказанная сложность.

## 7. PHPUnit и Pest

Предпочтительно реализовать интеграцию через PHPUnit Assert/ExpectationFailedException и небольшой reusable trait или assertion object.

Pest работает поверх PHPUnit, поэтому не делай отдельный Pest plugin в MVP, если trait или обычный API уже удобно используется в Pest.

Желаемый Pest-сценарий:

uses(QueryScalingAssertions::class);

it('loads posts with constant query count', function () {
    $this->assertQueriesScaleConstantly(...);
});

## 8. Warmup и нестабильность

Продумай явную семантику warmup.

Проблема: первый запуск может прогревать:

- container singletons;
- route resolution;
- schema metadata;
- ORM state;
- application caches.

Warmup не должен загрязнять измеряемый dataset или менять его состояние незаметно.

Предложи минимальный и предсказуемый вариант. Например:

- отдельный warmup run в собственной изоляции;
- затем новый clean scale run для реального измерения.

Не добавляй статистические repetitions и median в первый MVP без сильной необходимости.

# Архитектурные ограничения

Архитектура должна позволять в будущем добавить Doctrine DBAL/Symfony adapter, но MVP реализует только Laravel.

Не создавай чрезмерно абстрактный framework заранее.

Разумные компоненты могут включать:

- QueryCollector;
- LaravelQueryCollector;
- MeasurementSession;
- ScaleRunner;
- IsolationStrategy;
- LaravelTransactionIsolation;
- QueryFingerprint;
- ScaleMeasurement;
- ScalingExpectation;
- ConstantExpectation;
- AtMostSlopeExpectation;
- FailureReportRenderer;
- PHPUnit assertion integration.

Это не обязательный список. Предложи минимальную архитектуру и избегай классов, которые существуют только ради будущих гипотетических adapters.

Core-логика оценки scaling должна быть отделена от Laravel query collection, чтобы её можно было unit-тестировать без framework.

# Non-goals первой версии

Не реализовывать:

- автоматическое сканирование всего test suite;
- исторический baseline между коммитами;
- автоматический N+1 detector для всех тестов;
- EXPLAIN;
- slow-query profiling;
- total SQL duration budgets;
- static analysis;
- web dashboard;
- CI storage;
- machine learning или сложную классификацию роста;
- поддержку всех PHP frameworks;
- production runtime monitoring;
- автоматический rollback внешних side effects.

Пакет должен делать одну задачу хорошо: controlled multi-scale query-count assertions.

# Качество реализации

Используй:

- современную поддерживаемую версию PHP, выбранную после проверки текущей экосистемы;
- минимально разумный набор поддерживаемых версий Laravel и PHPUnit;
- Composer;
- PSR-4;
- strict_types;
- PHPUnit для тестов пакета;
- Orchestra Testbench для Laravel integration tests;
- PHPStan на строгом уровне;
- Laravel Pint или другой единый formatter;
- GitHub Actions с compatibility matrix;
- SemVer;
- Conventional Commits либо небольшие атомарные понятные commits.

Не заявляй поддержку версии PHP, Laravel или PHPUnit, которую CI реально не проверяет.

# Обязательные тестовые сценарии

Минимальный набор:

1. Eager-loaded endpoint проходит constant assertion.
2. Классический N+1 падает на scales [2, 5, 10].
3. Failure report показывает растущий SQL fingerprint.
4. Линейный сценарий проходит при max slope 1.
5. Тот же сценарий падает при max slope 0.
6. Запросы из populate не учитываются.
7. Запросы из warmup не учитываются.
8. Данные одного scale run не переходят в следующий.
9. Assertion корректно работает внутри уже открытой test transaction.
10. Исключение в populate корректно очищает transaction state.
11. Исключение в run корректно очищает collector и transaction state.
12. Повторный assertion в том же тесте не получает запросы первого assertion.
13. Listener не регистрируется бесконечно при повторных вызовах.
14. Можно выбрать конкретное DB connection.
15. Fingerprints учитывают connection name.
16. Report имеет стабильный порядок.
17. Невалидные scales дают раннюю понятную ошибку:
    - меньше двух значений;
    - дубликаты;
    - отрицательные значения;
    - неотсортированный набор, если API требует сортировку.
18. Callback, нарушивший transaction depth, даёт понятное сообщение.
19. Tolerance работает предсказуемо.
20. API удобно вызывается из Pest без отдельной сложной интеграции.

# Документация MVP

README должен содержать:

- какую проблему решает пакет;
- чем он отличается от query budgets и обычных N+1 detectors;
- Composer installation;
- Laravel setup;
- PHPUnit example;
- Pest example;
- constant assertion;
- max-slope assertion;
- warmup;
- transaction/isolation semantics;
- multiple connections;
- пример failure report;
- известные ограничения;
- предупреждение, что несколько scale factors не доказывают асимптотическую сложность;
- troubleshooting;
- compatibility table.

Добавь CHANGELOG.md и минимальный CONTRIBUTING.md.

# Ожидаемый рабочий процесс

1. Изучи текущий репозиторий.
2. Проверь актуальные версии PHP, PHPUnit, Laravel и Orchestra Testbench по официальным источникам.
3. Посмотри публичные API ближайших существующих query-count packages только для того, чтобы не повторить неудачные решения и не добавить конфликтующие названия.
4. Предложи 2–3 варианта публичного API.
5. Выбери рекомендуемый API и объясни компромиссы.
6. Спроектируй isolation и query collection.
7. Представь короткий design document:
   - public API;
   - lifecycle одного scale run;
   - архитектура компонентов;
   - transaction semantics;
   - error handling;
   - test strategy;
   - package structure;
   - MVP boundaries.
8. До написания production-кода дождись моего одобрения дизайна.
9. После одобрения составь подробный implementation plan.
10. Реализуй через TDD:
    - сначала failing test;
    - затем минимальная реализация;
    - затем refactor.
11. Делай небольшие проверяемые commits.
12. Перед заявлением о готовности запусти весь набор:
    - unit tests;
    - integration tests;
    - PHPStan;
    - formatter check;
    - Composer validation;
    - compatibility matrix или доступную локальную часть matrix.

Не спрашивай меня о мелких решениях, которые можно безопасно принять самостоятельно. Явно спрашивай только о решениях, меняющих публичный API, гарантии изоляции или scope MVP.

# Что должно быть в твоём первом ответе

Пока не пиши код.

Покажи:

1. Что обнаружено в текущем репозитории.
2. Какие версии PHP/Laravel/PHPUnit ты рекомендуешь поддерживать и почему.
3. Три варианта публичного API с короткими примерами.
4. Рекомендуемый вариант API.
5. Предлагаемую архитектуру.
6. Точную семантику constant, slope и tolerance.
7. Как будет работать изоляция каждого scale run.
8. Как будет решена проблема DB::listen listener lifecycle.
9. Основные технические риски.
10. Этапы MVP.
11. Вопросы, только если без ответа действительно нельзя зафиксировать дизайн.

После этого остановись и дождись утверждения дизайна.
