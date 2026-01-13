# Concurrency via custom extension

## build
```shell
rm -f build/sconcur.so build/sconcur.h && \
  CGO_CFLAGS=$(php-config --includes) \
  go build -buildmode=c-shared -o build/sconcur.so .
```
## echo test
```shell
php -d extension=./build/sconcur.so -r "echo \SConcur\Extension\ping('hello') . PHP_EOL;"
```
## example
```php
$sleeper = new \SConcur\Features\Sleeper\Sleeper();

$collection = new \SConcur\Features\Mongodb\Connection\Client('mongodb://localhost:27017')
    ->selectDatabase('example')
    ->selectCollection('example');

$waitGroup = \SConcur\WaitGroup::create();

$waitGroup->add(
    function () use ($sleeper) {
        $sleeper->sleep(seconds: 1);

        return 1;
    }
);

$waitGroup->add(
    function () use ($sleeper) {
        $sleeper->usleep(milliseconds: 11);

        return 2;
    }
);

$waitGroup->add(
    function () use ($collection) {
        $collection->insertOne(['name' => 'example']);

        return 3;
    }
);

$waitGroup->add(
    function () use ($collection) {
        $iterator = $collection->aggregate([
            [
                '$match' => ['name' => 'example'],
            ],
        ]);

        foreach ($iterator as $item) {
            echo $item['name'] . PHP_EOL;
        }

        return 4;
    }
);

$iterator = $waitGroup->iterate();

foreach ($iterator as $key => $item) {
    echo "result: $item" . PHP_EOL;
}
```
