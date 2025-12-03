# Concurrency via custom extension

## build
```shell
rm -f build/sconcur.so build/sconcur.h && \
  CGO_CFLAGS=$(php-config --includes) \
  go build -buildmode=c-shared -o build/sconcur.so .
```
## echo test
```shell
php -d extension=./build/sconcur.so -r "echo \SConcur\Extension\echo('hello') . PHP_EOL;"
```
