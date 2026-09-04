[English](mongodb.md) | Русский

# MongoDB

Асинхронная работа с MongoDB поверх официального Rust-драйвера. Каждая операция
уходит в расширение и выполняется в задаче рантайма, пока корутина приостановлена;
внутри `WaitGroup` операции идут параллельно, и общее время ограничено самой
медленной. Вне `WaitGroup` тот же API работает синхронно.

Документы обмениваются с расширением в MessagePack — том самом формате, который
PHP уже понимает через `ext-msgpack`. BSON-значения, которых в MessagePack нет,
приходят объектами `SConcur\Bson\*` (`ObjectId`, `UTCDateTime`, `Decimal128`, …);
документы и массивы — обычными PHP-массивами.

> `SConcur\Bson\*` повторяет API `MongoDB\BSON\*` один в один — те же
> конструкторы, геттеры, строковая и JSON-формы, — поэтому переход с нативного
> драйвера сводится к правке `use`-строк. Из PHP-расширений фиче нужно только
> `ext-msgpack`. Как это устроено и как добавить тип — в документе
> [Объекты через MessagePack](msgpack-objects.ru.md).

## Быстрый старт

```php
use SConcur\Features\Mongodb\Connection\Client;

$collection = new Client('mongodb://localhost:27017')
    ->selectDatabase('app')
    ->selectCollection('users');

$result = $collection->insertOne(['name' => 'Ann', 'age' => 30]);
echo $result->insertedId; // SConcur\Bson\ObjectId

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
| `timeoutMs` | 30000 | дедлайн операции, применяется как клиентский таймаут драйвера; превышение даёт ошибку вида `mongodb: … deadline exceeded` |
| `serverSelectionTimeoutMs` | 10000 | сколько ждать доступный сервер (`SetServerSelectionTimeout`), чтобы недоступная MongoDB падала быстро, а не висела на дефолтных 30 c драйвера |

Клиенты переиспользуются на стороне расширения по ключу
`uri + timeoutMs + serverSelectionTimeoutMs`, поэтому создавать `Client` на запрос
дёшево.

## Документы и типы BSON

Документ — это PHP-массив; вложенные документы и массивы тоже массивы.
BSON-значения, у которых нет эквивалента в MessagePack, — это объекты из
`SConcur\Bson\`:

```php
use SConcur\Bson\ObjectId;
use SConcur\Bson\UTCDateTime;

$collection->insertOne([
    '_id'       => new ObjectId(),
    'name'      => 'Ann',
    'createdAt' => new UTCDateTime(),       // сейчас
    'tags'      => ['a', 'b'],
]);

$document = $collection->findOne(['name' => 'Ann']);
$document['_id'];       // SConcur\Bson\ObjectId
$document['createdAt']; // SConcur\Bson\UTCDateTime
$document['tags'];      // ['a', 'b']
```

| Тип BSON | PHP |
|---|---|
| double, string, bool, null, int32 | нативные скаляры |
| document, array | `array` |
| int64 | `SConcur\Bson\Int64` |
| objectId | `SConcur\Bson\ObjectId` |
| date | `SConcur\Bson\UTCDateTime` |
| binary | `SConcur\Bson\Binary` |
| regex | `SConcur\Bson\Regex` |
| timestamp | `SConcur\Bson\Timestamp` |
| decimal128 | `SConcur\Bson\Decimal128` |
| javascript | `SConcur\Bson\Javascript` |
| minKey / maxKey | `SConcur\Bson\MinKey` / `MaxKey` |

Пустой документ читается пустым массивом — у PHP один тип и для `{}`, и для `[]`,
так что различить их можно только по содержимому. Запись такого массива обратно
сохранит пустой массив — ровно как это делал путь через `ext-mongodb`.

Там, где ожидается документ, принимается и обычный объект — `(object) [...]` или
то, что возвращает `json_decode()` без `associative: true`, — он сохраняется
поддокументом. Читается обратно массивом, как любой документ. Любой другой объект
в документе — ошибка: такому значению нечем стать в BSON.

У каждого класса те же методы, что у его аналога `MongoDB\BSON\*` —
`getData()`/`getType()` у `Binary`, `getPattern()`/`getFlags()` у `Regex`,
`toDateTime()`/`toDateTimeImmutable()` у `UTCDateTime`, `__toString()` и
`jsonSerialize()` везде, где они есть у драйвера. `int64` завёрнут по той же
причине, по которой его заворачивает драйвер: без обёртки тип не пережил бы
чтение с последующей записью — значение, влезающее в int32, вернулось бы им.

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

$collection->distinct('city', filter: ['age' => ['$gt' => 18]], collation: null);  // массив значений

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
$collection->deleteOne(['name' => 'Ann'], hint: null, collation: null);               // DeleteResult
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
$collection->findOneAndDelete(filter: ['name' => 'Ann'], projection: null);

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

`Database` даёт `listCollections()` (список имён коллекций),
`command(['ping' => 1])` (произвольная команда → документ результата) и
`selectCollection()`. `Client` даёт `listDatabases()` (список имён баз) и
`selectDatabase()`.

## Результаты

| Метод | Результат | Поля |
|--------|--------|--------|
| `insertOne` | `InsertOneResult` | `insertedId` (`ObjectId\|Int64\|string\|int\|float\|null`) |
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
батчи из расширения (по `batchSize`), поэтому крупная выборка не буферизуется целиком ни в
расширении, ни в PHP:

```php
foreach ($collection->find(['active' => true], batchSize: 100) as $document) {
    if ($enough) {
        break; // ранний выход — курсор корректно закрывается
    }
}
```

Ранний `break`, исключение или остановка `WaitGroup` закрывают курсор на
стороне расширения (`cursor.Close` → `killCursors`). Каждый курсор в конкурентных флоу
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

- Все операции выполняет `go.mongodb.org/mongo-driver/v2` в задаче рантайма: блокирующий
  драйвер берётся как есть, конкурентность даёт рантайм.
- Пул клиентов (`ext-go-legacy/internal/features/mongodb/connection`) — `*mongo.Client` на
  ключ `uri + timeoutMs + serverSelectionTimeoutMs`, с refcount и вытеснением
  простаивающих клиентов (TTL 5 минут, проверка раз в минуту). Пока есть
  незавершённые операции, клиент не отключается.
- Курсоры (`states/find_state`, `states/aggregation_state`) — расширение держит курсор
  состоянием и отдаёт батчи по запросу `next`; закрывается при исчерпании, раннем
  выходе или остановке флоу. Закрытие идёт на свежем контексте, потому что
  контекст задачи к этому моменту уже отменён.
- Документы конвертируются между BSON и MessagePack ровно один раз на сообщение,
  на внешней границе (`payloads.UnmarshalParams` на входе, маршалинг результата на
  выходе). Всё внутри — фильтры, апдейты, стадии пайплайна, разбор bulkWrite,
  парсеры опций — читает обычный BSON, поэтому новой команде собственный код
  конвертации не нужен. См.
  [Объекты через MessagePack](msgpack-objects.ru.md).

## Ограничения

- Курсор `find`/`aggregate` нужно либо дочитать, либо прервать (`break`) — до
  закрытия он держит ресурс на сервере.
- Из типов BSON, объявленных спецификацией устаревшими, `symbol` читается строкой,
  `undefined` — как `null`; `dbPointer` не поддержан, и документ с ним не
  декодируется. Встречаются они только в данных очень старых драйверов.
- Принудительное включение `msgpack.php_only` действует на весь процесс — см.
  [Объекты через MessagePack](msgpack-objects.ru.md#флаг-расширения).
- Действуют общие ограничения библиотеки — см. [README](../README.ru.md).

## Конвертация объектов

Значения BSON, невыразимые в MessagePack — идентификатор, дата, decimal, —
пересекают границу PHP-объектами, в той кодировке, которой `ext-msgpack` пользуется
для объектов. Этот механизм не привязан к MongoDB и описан отдельно:
[Объекты через MessagePack](msgpack-objects.ru.md) разбирает формат, требование
`msgpack.php_only`, пин версии `ext-msgpack`, повторные экземпляры, а также как
добавить тип и как его тестировать.

Сторона BSON у этого механизма — таблица типов в разделе
[Документы и типы BSON](#документы-и-типы-bson): добавить тип значит написать класс в
`src/Bson/`, повторяющий двойника `MongoDB\BSON\*`, добавить `case` в кодировщик и
ветку в декодировщик, а также значение в `TestRoundTripsEveryBSONType` и пару в
`BsonDriverParityTest`.
