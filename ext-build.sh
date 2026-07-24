cd ext/ &&
  rm -f build/sconcur.so build/sconcur.h && \
    CGO_CFLAGS="-I/usr/local/include/php -I/usr/local/include/php/main -I/usr/local/include/php/TSRM -I/usr/local/include/php/Zend -I/usr/local/include/php/ext -I/usr/local/include/php/ext/date/lib" \
    go build -trimpath -ldflags="-s -w" -buildmode=c-shared -o build/sconcur.so .

# -ldflags="-s -w": strip the debug info and Go symbol table (the dynamic symbol
#   table the exported cgo functions need for PHP to bind them is kept), so the .so
#   is smaller and loads faster; no runtime behaviour change.
# -trimpath: drop absolute build paths from the binary for reproducible builds.
