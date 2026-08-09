[English](mongodb.md) | Русский

# MongoDB

Асинхронная работа с MongoDB поверх официального Go-драйвера. Каждая операция
уходит в Go-расширение и выполняется в горутине, пока корутина приостановлена;
внутри `WaitGroup` операции идут параллельно, и общее время ограничено самой
медленной. Вне `WaitGroup` тот же API работает синхронно.

Документы обмениваются с Go-стороной сырым BSON и декодируются нативно
расширением `ext-mongodb` — тем же кодом, что использует официальный драйвер.
Поэтому значения приходят нативными типами `MongoDB\BSON\*` (`ObjectId`,
`UTCDateTime`, `Decimal128`, …), а документы и массивы — обычными PHP-массивами.

> Требуется `ext-mongodb` — только для кодирования/декодирования BSON на стороне
> PHP; сеть держит Go.

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

## Соединение

`Client` → `Database` → `Collection`:

```php
$client     = new Client(uri: 'mongodb://user:pass@localhost:27017');
$database   = $client->selectDatabase('app');
$collection = $database->selectCollection('users');
```

| Параметр `Client` | Дефолт | Назначение |
|---|---|---|
| `uri` | — | строка подключения MongoDB |
| `timeoutMs` | 30000 | дедлайн операции; Go применяет его как CSOT драйвера (`SetTimeout`), превышение даёт ошибку вида `mongodb: … deadline exceeded` |
| `serverSelectionTimeoutMs` | 10000 | сколько ждать доступный сервер (`SetServerSelectionTimeout`), чтобы недоступная MongoDB падала быстро, а не висела на дефолтных 30 c драйвера |

Клиенты переиспользуются на Go-стороне по ключу
`uri + timeoutMs + serverSelectionTimeoutMs`, поэтому создавать `Client` на запрос
дёшево.

## Документы и типы BSON

Документ — это PHP-массив; вложенные документы и массивы тоже массивы. Скалярные
BSON-значения используют типы официального драйвера:

```php
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

$collection->insertOne([
    '_id'       => new ObjectId(),
    'name'      => 'Ann',
    'createdAt' => new UTCDateTime(),       // сейчас
    'tags'      => ['a', 'b'],
]);

$document = $collection->findOne(['name' => 'Ann']);
$document['_id'];       // MongoDB\BSON\ObjectId
$document['createdAt']; // MongoDB\BSON\UTCDateTime
$document['tags'];      // ['a', 'b']
```

## Операции коллекции

```php
// вставка
$collection->insertOne(['name' => 'Ann']);                        // InsertOneResult
$collection->insertMany([['name' => 'Ann'], ['name' => 'Bob']]);  // InsertManyResult

// чтение
$collection->findOne(
    filter: ['name' => 'Ann'],
    projection: ['name' => 1, '_id' => 0],   // опц.
    hint: ['name' => 1],                     // опц.
    collation: ['locale' => 'en'],           // опц.
);

$collection->find(                            // курсор (Iterator), батчами
    filter: ['age' => ['$gt' => 18]],
    projection: null,
    sort: ['age' => 1],
    limit: 0,
    skip: 0,
    batchSize: 50,
    hint: null,
    collation: null,
);

$collection->aggregate(                       // курсор (Iterator)
    pipeline: [
        ['$match' => ['age' => ['$gt' => 18]]],
        ['$group' => ['_id' => '$city', 'count' => ['$sum' => 1]]],
    ],
    batchSize: 50,
);

$collection->distinct('city', filter: ['age' => ['$gt' => 18]]);  // массив значений

// подсчёт
$collection->countDocuments(['age' => ['$gt' => 18]]);  // int (точно)
$collection->estimatedDocumentCount();                  // int (по метаданным, быстро)

// изменение
$collection->updateOne(
    filter: ['name' => 'Ann'],
    update: ['$set' => ['age' => 31]],
    upsert: false,
    arrayFilters: null,
    hint: null,
    collation: null,
);                                                       // UpdateResult

$collection->updateMany(filter: ['active' => true], update: ['$inc' => ['score' => 1]]);
$collection->replaceOne(filter: ['name' => 'Ann'], replacement: ['name' => 'Ann', 'age' => 31], upsert: false);
$collection->deleteOne(['name' => 'Ann']);               // DeleteResult
$collection->deleteMany(['active' => false]);

// find-and-modify (возвращает документ или null)
$collection->findOneAndUpdate(
    filter: ['name' => 'Ann'],
    update: ['$inc' => ['age' => 1]],
    projection: null,
    upsert: false,
    returnDocument: true,   // true — новая версия, false — предыдущая
    arrayFilters: null,
    hint: null,
    collation: null,
);

$collection->findOneAndReplace(filter: ['name' => 'Ann'], replacement: ['name' => 'Ann', 'age' => 31], returnDocument: true);
$collection->findOneAndDelete(filter: ['name' => 'Ann']);

// индексы
$collection->createIndex(['name' => 1], name: null);     // имя индекса (string)
$collection->createIndexes([
    ['keys' => ['name' => 1]],
    ['keys' => ['city' => 1, 'age' => -1], 'name' => 'city_age'],
]);                                                       // массив имён
$collection->listIndexes();                               // массив документов индексов
$collection->dropIndex(['name' => 1]);                    // по ключам или по строке имени
$collection->makeIndexNameByKeys(['name' => 1]);          // вычислить имя локально

// вся коллекция
$collection->drop();
$collection->rename(target: 'users_archive', dropTarget: false);
```

`bulkWrite` принимает список операций, каждая — карта
`['<тип>' => [аргументы...]]`:

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

Третий элемент `updateOne`/`updateMany`/`replaceOne` — опции
(`['upsert' => bool]`); неизвестный тип операции бросает
`InvalidMongodbBulkWriteOperationException`.

`Database` даёт `listCollections()` (имена коллекций), `command(['ping' => 1])`
(произвольная команда → документ результата) и `selectCollection()`.

## Результаты

| Метод | Результат | Поля |
|--------|--------|--------|
| `insertOne` | `InsertOneResult` | `insertedId` (`ObjectId\|string\|int\|float\|null`) |
| `insertMany` | `InsertManyResult` | `insertedIds`, `insertedCount` |
| `updateOne`/`updateMany`/`replaceOne` | `UpdateResult` | `matchedCount`, `modifiedCount`, `upsertedCount`, `upsertedId` |
| `deleteOne`/`deleteMany` | `DeleteResult` | `deletedCount` |
| `bulkWrite` | `BulkWriteResult` | `insertedCount`, `matchedCount`, `modifiedCount`, `deletedCount`, `upsertedCount`, `upsertedIds` |
| `findOne*` | `array\|null` | документ |
| `find`/`aggregate` | `Iterator` | курсор по документам |
| `countDocuments`/`estimatedDocumentCount` | `int` | |
| `distinct` | `array` | значения |

## Курсоры и стриминг

`find()` и `aggregate()` возвращают `Iterator`, который лениво тянет следующие
батчи из Go (по `batchSize`), поэтому крупная выборка не буферизуется целиком ни в
расширении, ни в PHP:

```php
foreach ($collection->find(['active' => true], batchSize: 100) as $document) {
    if ($enough) {
        break; // ранний выход — курсор корректно закрывается
    }
}
```

Ранний `break`, исключение или остановка `WaitGroup` закрывают курсор на
Go-стороне (`cursor.Close` → `killCursors`). Каждый курсор в конкурентных флоу
независим.

## Конкурентность

Внутри `WaitGroup` операции из разных корутин идут параллельно:

```php
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

Выигрыш растёт с ценой операций — общее время стремится к самой медленной, а не к
их сумме. Что это значит в цифрах (и где нативный драйвер всё же выигрывает) — в
[бенчмарках](benchmarks.ru.md#mongodb).

## Внутреннее устройство

- Все операции выполняет `go.mongodb.org/mongo-driver/v2` в горутине: блокирующий
  драйвер берётся как есть, конкурентность даёт рантайм.
- Пул клиентов (`ext/internal/features/mongodb/connection`) — `*mongo.Client` на
  ключ `uri + timeoutMs + serverSelectionTimeoutMs`, с refcount и вытеснением
  простаивающих клиентов (TTL 5 минут, проверка раз в минуту). Операции в полёте
  клиента не отключают.
- Курсоры (`states/find_state`, `states/aggregation_state`) — Go держит курсор
  состоянием и отдаёт батчи по запросу `next`; закрывается при исчерпании, раннем
  выходе или остановке флоу. Закрытие идёт на свежем контексте, потому что
  контекст задачи к этому моменту уже отменён.

## Ограничения

- Требуется `ext-mongodb` (типы BSON и кодирование/декодирование).
- Курсор `find`/`aggregate` нужно либо дочитать, либо прервать (`break`) — до
  закрытия он держит ресурс на сервере.
- Действуют общие ограничения библиотеки — см. [README](../README.ru.md).
