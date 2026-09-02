#!/usr/bin/env sh
#
# Builds the Rust core spike into ext-rust/build/sconcur.so — a drop-in for
# ext/build/sconcur.so, loadable by an unmodified PHP package.
#
# Two steps, mirroring what cgo does in one for the Go build:
#   1. cargo builds src/ into a static archive (libsconcur_core.a);
#   2. gcc compiles the PHP glue (sconcur.c) and links it against that archive.
#
# Run inside the `php` container, which carries both toolchains and the PHP
# headers: `make ext-rust-build`.
set -e

cd "$(dirname "$0")"

PHP_INCLUDES="-I/usr/local/include/php \
  -I/usr/local/include/php/main \
  -I/usr/local/include/php/TSRM \
  -I/usr/local/include/php/Zend \
  -I/usr/local/include/php/ext \
  -I/usr/local/include/php/ext/date/lib"

rm -f build/sconcur.so build/sconcur.o

cargo build --release

gcc -O2 -fPIC -std=gnu11 -c sconcur.c -o build/sconcur.o -Iinclude $PHP_INCLUDES

# The archive goes last: the linker resolves the glue's undefined symbols out of
# it. -ldl/-lm/-lpthread are what the Rust standard library asks for; the PHP
# symbols the glue uses are resolved at dlopen time against the running binary,
# so the shared object is deliberately left with them undefined.
gcc -shared -o build/sconcur.so \
  build/sconcur.o \
  target/release/libsconcur_core.a \
  -lpthread -ldl -lm

echo "built: $(ls -la build/sconcur.so | awk '{print $5" bytes"}')"
