# MongoDB

Асинхронная работа с MongoDB поверх официального Go-драйвера. Каждая операция
уходит в Go-расширение и выполняется в горутине, пока корутина приостановлена, —
десятки операций летят «веером», и общее время ограничено самой медленной, а не
суммой. Вне `WaitGroup` тот же API работает синхронно.

Документы обмениваются с Go-частью как **сырой BSON** и декодируются нативно через
расширение `ext-mongodb` — тем же кодом, что и официальный драйвер. Поэтому
значения приходят нативными типами `MongoDB\BSON\*` (`ObjectId`, `UTCDateTime`,
`Decimal128`, …), а документы и массивы — обычными PHP-массивами.

> Требуется расширение **`ext-mongodb`** (используется только для BSON-кодирования
> на стороне PHP; сетевую работу выполняет Go).

## Оглавление

- [Быстрый старт](#быстрый-старт)
- [Подключение](#подключение)
- [Документы и типы BSON](#документы-и-типы-bson)
- [Операции коллекции](#операции-коллекции)
- [Результаты](#результаты)
- [Курсоры и стриминг](#курсоры-и-стриминг)
- [База данных](#база-данных)
- [Конкурентность](#конкурентность)
- [Таймауты](#таймауты)
- [Внутреннее устройство](#внутреннее-устройство)
- [Ограничения](#ограничения)

## Быстрый старт

```php
use SConcur\Features\Mongodb\Connection\Client;

$collection = new Client('mongodb://localhost:27017')
    ->selectDatabase('app')
    ->selectCollection('users');

$result = $collection->insertOne(['name' => 'Ann', 'age' => 30]);
echo $result->insertedId; // MongoDB\BSON\ObjectId

$user = $collection->findOne(['name' => 'Ann']);

foreach ($collection->find(['age' => ['$gt' => 18]]) as $document) {
    echo $document['name'] . PHP_EOL;
}
```

В `WaitGroup::add(...)` те же вызовы исполняются конкурентно (см.
[Конкурентность](#конкурентность)).

## Подключение

`Client` → `Database` → `Collection`:

```php
$client     = new Client(uri: 'mongodb://user:pass@localhost:27017');
$database   = $client->selectDatabase('app');
$collection = $database->selectCollection('users');
```

Конструктор `Client`:

| Параметр                   | По умолчанию | Назначение |
|----------------------------|--------------|------------|
| `uri`                      | —            | строка подключения MongoDB |
| `timeoutMs`                | 30000        | предельное время операции (CSOT) |
| `serverSelectionTimeoutMs` | 10000        | сколько ждать доступный сервер |

Клиенты переиспользуются на стороне Go по ключу `uri + таймауты` — создавать
`Client` на каждый запрос дёшево (см. [Внутреннее устройство](#внутреннее-устройство)).

## Документы и типы BSON

Документ — это PHP-массив; вложенные документы/массивы — тоже массивы. Скалярные
BSON-значения используют типы официального драйвера:

```php
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

$collection->insertOne([
    '_id'       => new ObjectId(),
    'name'      => 'Ann',
    'createdAt' => new UTCDateTime(),       // now
    'tags'      => ['a', 'b'],
]);

$document = $collection->findOne(['name' => 'Ann']);
$document['_id'];       // MongoDB\BSON\ObjectId
$document['createdAt']; // MongoDB\BSON\UTCDateTime
$document['tags'];      // ['a', 'b']
```

## Операции коллекции

### Вставка

```php
$collection->insertOne(['name' => 'Ann']);                 // InsertOneResult
$collection->insertMany([['name' => 'Ann'], ['name' => 'Bob']]); // InsertManyResult
```

### Чтение

```php
// один документ (или null)
$collection->findOne(
    filter: ['name' => 'Ann'],
    projection: ['name' => 1, '_id' => 0],   // опц.
    hint: ['name' => 1],                     // опц.
    collation: ['locale' => 'en'],           // опц.
);

// курсор (Iterator), батчами
$collection->find(
    filter: ['age' => ['$gt' => 18]],
    projection: null,
    sort: ['age' => 1],
    limit: 0,
    skip: 0,
    batchSize: 50,
    hint: null,
    collation: null,
);

// агрегация — курсор (Iterator)
$collection->aggregate(
    pipeline: [
        ['$match' => ['age' => ['$gt' => 18]]],
        ['$group' => ['_id' => '$city', 'count' => ['$sum' => 1]]],
    ],
    batchSize: 50,
);

$collection->distinct('city', filter: ['age' => ['$gt' => 18]]); // array значений
```

### Подсчёт

```php
$collection->countDocuments(['age' => ['$gt' => 18]]); // int (точный)
$collection->estimatedDocumentCount();                 // int (по метаданным, быстро)
```

### Изменение

```php
$collection->updateOne(
    filter: ['name' => 'Ann'],
    update: ['$set' => ['age' => 31]],
    upsert: false,
    arrayFilters: null,
    hint: null,
    collation: null,
); // UpdateResult

$collection->updateMany(filter: ['active' => true], update: ['$inc' => ['score' => 1]]);

$collection->replaceOne(filter: ['name' => 'Ann'], replacement: ['name' => 'Ann', 'age' => 31], upsert: false);

$collection->deleteOne(['name' => 'Ann']);  // DeleteResult
$collection->deleteMany(['active' => false]);
```

### Find-and-modify (возвращают документ или `null`)

```php
$collection->findOneAndUpdate(
    filter: ['name' => 'Ann'],
    update: ['$inc' => ['age' => 1]],
    projection: null,
    upsert: false,
    returnDocument: true,   // true — вернуть новую версию, false — прежнюю
    arrayFilters: null,
    hint: null,
    collation: null,
);

$collection->findOneAndReplace(
    filter: ['name' => 'Ann'],
    replacement: ['name' => 'Ann', 'age' => 31],
    returnDocument: true,
);

$collection->findOneAndDelete(filter: ['name' => 'Ann']);
```

### Индексы

```php
$collection->createIndex(['name' => 1], name: null);     // имя индекса (string)
$collection->createIndexes([
    ['keys' => ['name' => 1]],
    ['keys' => ['city' => 1, 'age' => -1], 'name' => 'city_age'],
]);                                                       // array имён
$collection->listIndexes();                              // array документов индексов
$collection->dropIndex(['name' => 1]);                   // по ключам или по имени-строке
$collection->makeIndexNameByKeys(['name' => 1]);         // вычислить имя индекса локально
```

### Коллекция целиком

```php
$collection->drop();
$collection->rename(target: 'users_archive', dropTarget: false);
```

### bulkWrite

Список операций — каждая это map `['<тип>' => [аргументы...]]`:

```php
$collection->bulkWrite([
    ['insertOne'  => [['name' => 'Ann']]],
    ['updateOne'  => [['name' => 'Ann'], ['$set' => ['age' => 31]], ['upsert' => true]]],
    ['updateMany' => [['active' => true], ['$inc' => ['score' => 1]]]],
    ['replaceOne' => [['name' => 'Bob'], ['name' => 'Bob', 'age' => 40], ['upsert' => false]]],
    ['deleteOne'  => [['name' => 'Cleo']]],
    ['deleteMany' => [['active' => false]]],
]); // BulkWriteResult
```

Третий элемент у `updateOne`/`updateMany`/`replaceOne` — опции (`['upsert' => bool]`).

## Результаты

| Метод | Результат | Поля |
|-------|-----------|------|
| `insertOne` | `InsertOneResult` | `insertedId` (`ObjectId\|string\|int\|float\|null`) |
| `insertMany` | `InsertManyResult` | `insertedIds` (array), `insertedCount` (int) |
| `updateOne`/`updateMany`/`replaceOne` | `UpdateResult` | `matchedCount`, `modifiedCount`, `upsertedCount`, `upsertedId` |
| `deleteOne`/`deleteMany` | `DeleteResult` | `deletedCount` |
| `bulkWrite` | `BulkWriteResult` | `insertedCount`, `matchedCount`, `modifiedCount`, `deletedCount`, `upsertedCount`, `upsertedIds` |
| `findOne*` | `array\|null` | документ |
| `find`/`aggregate` | `Iterator` | курсор по документам |
| `countDocuments`/`estimatedDocumentCount` | `int` | |
| `distinct` | `array` | значения |

## Курсоры и стриминг

`find()` и `aggregate()` возвращают `Iterator`, который **лениво** тянет следующие
батчи из Go (по `batchSize`) — большая выборка не буферизуется целиком ни в
расширении, ни в PHP:

```php
foreach ($collection->find(['active' => true], batchSize: 100) as $document) {
    // обрабатывается батчами по 100
    if ($enough) {
        break; // ранний выход — курсор корректно закрывается
    }
}
```

Ранний `break`, исключение или остановка `WaitGroup` закрывают курсор на стороне
Go (освобождается `killCursors`). Внутри транзакций/конкурентных потоков каждый
курсор независим.

## База данных

```php
$database = $client->selectDatabase('app');

$database->listCollections();              // array имён коллекций
$database->command(['ping' => 1]);         // произвольная команда → документ-результат
$database->selectCollection('users');
```

## Конкурентность

В `WaitGroup` операции разных корутин выполняются параллельно:

```php
use SConcur\WaitGroup;

$waitGroup = WaitGroup::create();

$waitGroup->add(fn () => $collection->insertOne(['name' => 'Ann']));
$waitGroup->add(fn () => $collection->countDocuments(['active' => true]));
$waitGroup->add(function () use ($collection) {
    $items = [];

    foreach ($collection->aggregate([['$match' => ['active' => true]]]) as $document) {
        $items[] = $document;
    }

    return $items;
});

$waitGroup->waitAll();
```

Выигрыш «веера» тем больше, чем дороже/латентнее операции (тяжёлые агрегации,
удалённый кластер): общее время стремится к самой медленной операции, а не к их
сумме.

## Таймауты

- **`timeoutMs`** — предельное время операции; Go применяет его как CSOT драйвера
  (`SetTimeout`). Превышение → ошибка вида `mongodb: … deadline exceeded`.
- **`serverSelectionTimeoutMs`** — сколько ждать доступный сервер
  (`SetServerSelectionTimeout`), чтобы недоступный MongoDB не подвешивал задачу на
  драйверный дефолт (30s). Недостижимый сервер падает быстро.

Оба параметра задаются на `Client` и входят в ключ пула.

## Внутреннее устройство

- **Официальный Go-драйвер.** Все операции выполняет `go.mongodb.org/mongo-driver`
  в горутине; блокирующий драйвер используется как есть, конкурентность даёт рантайм.
- **Пул клиентов** (`ext/internal/features/mongodb/connection`) — `*mongo.Client`
  по ключу `uri + timeout + serverSelectionTimeout`, с refcount и вытеснением
  простаивающих клиентов (TTL 5 минут). In-flight операции клиент не отключают.
- **Курсоры** (`states/find_state`, `states/aggregation_state`) — Go держит курсор
  как «состояние» и отдаёт батчи по запросу `next`; закрывается при исчерпании,
  раннем выходе или остановке потока (на свежем контексте, т.к. контекст задачи к
  тому моменту отменён).
- **Обмен документами — сырой BSON**, декодирование нативное через `ext-mongodb`
  (`MongoDB\BSON\Document`).

## Ограничения

- Требуется расширение **`ext-mongodb`** (для BSON-типов и кодирования).
- Курсор `find`/`aggregate` стоит либо дочитать, либо прервать (`break`) — он держит
  ресурс на сервере до закрытия.
- Применимы общие ограничения библиотеки: только CLI/NTS, никаких `pcntl_fork`
  после загрузки расширения, не завершать процесс при активных задачах/курсорах
  (см. [README](../README.md)).
