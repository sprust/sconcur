English | [Русский](cli.ru.md)

# CLI commands

The package installs three executables into `vendor/bin` (the `bin` section of
`composer.json`): `sconcur-load`, `sconcur-status`, `sconcur-server`. From the
repository they run as `bin/<command>`, from a consumer application as
`vendor/bin/<command>`.

## sconcur-load — download the extension

Downloads the built extension `.so` from GitHub Releases. The project is
experimental, so the extension is not published in PECL or other registries. Before
downloading, check [Tested versions](../README.md#tested-versions): the extension
is built against specific versions of PHP, Go and the database servers, and
compatibility is guaranteed only with them.

The version is not set manually — it comes from
`Extension::REQUIRED_EXTENSION_VERSION` (the version the PHP package is built
against), so the downloaded file is guaranteed to pass the check on load. The asset
`https://github.com/sprust/sconcur/releases/download/v<version>/sconcur.so` is
downloaded; there is no rolling `latest` release, so `.../releases/latest/download/...`
must not be used.

The only argument is the local destination path:

```sh
vendor/bin/sconcur-load ./ext             # a directory → <directory>/sconcur.so
vendor/bin/sconcur-load ./ext/sconcur.so  # or an exact file path
```

- If the path is an existing directory, the file is saved as
  `<directory>/sconcur.so`; otherwise the argument is treated as a full file path.
- The destination directory must exist. Write permissions are checked up-front:
  an existing file must be overwritable, otherwise the directory must be writable.
- The download goes into a temporary file `<path>.tmp` and is then atomically
  renamed, so the target is never left half-written. On error or an empty response
  the temporary file is removed.
- GitHub redirects to the CDN are followed automatically; any status other than
  `200` is an error with no file written.
- Return codes: `0` — downloaded, `1` — error (missing argument, missing directory,
  no permission, bad HTTP status, empty file). Errors go to `STDERR`.

After downloading, enable the extension with an interpreter flag:

```sh
php -d extension=./ext/sconcur.so your-script.php
```

Or put it straight into PHP's extension directory and enable it permanently via
`.ini` — then the flag is not needed:

```dockerfile
RUN vendor/bin/sconcur-load "$(php-config --extension-dir)/sconcur.so" \
    && echo "extension=sconcur.so" > /usr/local/etc/php/conf.d/docker-php-ext-sconcur.ini
```

### Installing into the image before `composer install`

`bin/sconcur-load` is part of the package and appears only after dependencies are
installed. To place the extension into a system directory at an earlier build stage
(for layer caching), take the exact version from `composer.lock` — it pins the
resolved version rather than a constraint — and download the asset directly. Only
`composer.lock` needs to be copied, `vendor/` is not required:

```dockerfile
COPY composer.lock ./

RUN set -eux; \
    version="$(jq -r '.packages[] | select(.name=="sconcur/sconcur") | .version' composer.lock | sed 's/^v//')"; \
    curl -fSL --connect-timeout 10 --retry 3 -4 \
      "https://github.com/sprust/sconcur/releases/download/v${version}/sconcur.so" \
      -o "$(php-config --extension-dir)/sconcur.so"; \
    echo "extension=sconcur.so" > /usr/local/etc/php/conf.d/docker-php-ext-sconcur.ini
```

In `composer.lock` the version may carry a `v` prefix (`v0.11.0`), so the leading
`v` is stripped and added back in the URL — otherwise it becomes `vv0.11.0`. `jq`
and `curl` are required in this build layer.

## sconcur-status — check the installation

Reports whether the `sconcur` extension is loaded and whether its version matches
the version the package is built against. By default it prints a human-readable
report; with `--json` — a single machine-readable line.

```sh
vendor/bin/sconcur-status
#   sconcur status
#     extension installed:  yes
#     package version:      0.11.0
#     extension version:    0.11.0
#     ready:                yes

vendor/bin/sconcur-status --json
#   {"extension_installed":true,"package_version":"0.11.0","extension_version":"0.11.0","ready":true}
```

The extension must be enabled for the same process, otherwise the script cannot see
it: `php -d extension=./ext/sconcur.so vendor/bin/sconcur-status`.

JSON fields: `extension_installed` — whether the extension is loaded in the current
process; `package_version` — the version the package is built against
(`Extension::REQUIRED_EXTENSION_VERSION`); `extension_version` — the version of the
loaded extension (`null` if not loaded); `ready` — `true` only when the extension
is loaded and its version exactly matches.

The return code is `0` when `ready=true` and `1` otherwise, so a pipeline can
branch without parsing the output. The release CI is built on this: it lets the
build through only when `ready` is true, and tags the release as
`v<extension_version>`.

## sconcur-server — worker master

Starts and supervises a pool of worker processes (scaling across cores via
`SO_REUSEPORT`, restarting crashed ones, graceful shutdown). Commands
`start` / `status` / `stop` / `reload`, all taking a `--configPath` flag pointing to the
master's JSON config; `--group=NAME` narrows `status` and `reload` to one pool
(`start` and `stop` accept it and check the name, but serve the whole config):

```sh
vendor/bin/sconcur-server start --configPath=/app/master.json
```

Details — config parameters, restart policy, logging and graceful shutdown — are in
[Worker master](worker-master.md).
