cd ext/ &&
  rm -f build/sconcur.so build/sconcur.h && \
    CGO_CFLAGS="-I/usr/local/include/php -I/usr/local/include/php/main -I/usr/local/include/php/TSRM -I/usr/local/include/php/Zend -I/usr/local/include/php/ext -I/usr/local/include/php/ext/date/lib" \
    go build -buildmode=c-shared -o build/sconcur.so .