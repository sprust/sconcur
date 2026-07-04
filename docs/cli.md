English | [Русский](cli.ru.md)

# CLI commands

The package installs three executables into `vendor/bin` (the `bin` section of
`composer.json`): `sconcur-load`, `sconcur-status`, `sconcur-server`. From the
repository they can be run as `bin/<command>`, from a consumer application as
`vendor/bin/<command>`.

## Table of contents

- [sconcur-load — download the extension](#sconcur-load--download-the-extension)
- [sconcur-status — check the installation](#sconcur-status--check-the-installation)
- [sconcur-server — worker master](#sconcur-server--worker-master)

## sconcur-load — download the extension

The project is experimental, so the extension is not published in PHP extension
registries (PECL and the like) — the built `.so` is taken directly from this
command's GitHub Releases (or manually from the release link), not through a
third-party extension repository.

Before downloading, check the "Tested versions" section in the
[main README](../README.md): the extension is built for specific versions of PHP,
Go and the servers (MongoDB, MySQL, PostgreSQL), and compatibility is guaranteed
only with them.

Downloads the built extension `.so` from GitHub Releases. The version is not set
manually — it is taken from `Extension::REQUIRED_EXTENSION_VERSION` (the version
the PHP package is built against), so the downloaded file is guaranteed to pass
the version check on load (`Extension::checkExtension`). The asset
`https://github.com/sprust/sconcur/releases/download/v<version>/sconcur.so` is
downloaded.

The only argument is the local destination path:

```sh
# a directory: the file lands as <directory>/sconcur.so
vendor/bin/sconcur-load ./ext

# or an exact file path
vendor/bin/sconcur-load ./ext/sconcur.so
```

Behavior:

- If the path is an existing directory, the file is saved as `<directory>/sconcur.so`;
  otherwise the argument is treated as a full file path.
- The destination directory must exist. Write permissions are checked up-front
  (before the download): an existing file must be overwritable, otherwise the
  directory must be writable.
- The download goes into a temporary file `<path>.tmp`, then is atomically renamed
  to the target path, so the target is never left half-written. On error or an
  empty response the temporary file is removed.
- GitHub HTTP redirects to the CDN are followed automatically; a response other
  than `200` is an error with no file written.

Return codes: `0` — file downloaded, `1` — error (missing argument, missing
directory, no permission, unsuccessful HTTP status, empty file). Error messages go
to `STDERR`.

After downloading, the extension is enabled with an interpreter flag:

```sh
php -d extension=./ext/sconcur.so your-script.php
```

Or download it straight into PHP's extension directory and enable it permanently
via `.ini` — then the flag is not needed. Example for Docker (composer
dependencies are already installed, so `vendor/bin/sconcur-load` is available and
picks the right version itself):

```dockerfile
RUN vendor/bin/sconcur-load "$(php-config --extension-dir)/sconcur.so" \
    && echo "extension=sconcur.so" > /usr/local/etc/php/conf.d/docker-php-ext-sconcur.ini
```

The script downloads the asset from the versioned release `v<version>`, where the
version is exactly the one the package is built against
(`Extension::REQUIRED_EXTENSION_VERSION`), so the extension and the package cannot
drift apart in version. There is no rolling `latest` release (CI marks the
versioned release itself as "Latest"), so you should not download via
`.../releases/latest/download/...` — only by the exact tag, which is what the
script does.

### Installing into the image before `composer install`

`bin/sconcur-load` is part of the package and appears only after dependencies are
installed (in `vendor/`). If the extension needs to be placed into a system
directory earlier — at an early build stage of the image, before `composer install`
(for example, for layer caching) — the script is not yet available. In that case
the exact version is taken from `composer.lock` (which, unlike `composer.json`,
pins the resolved version rather than a constraint) and the asset is downloaded
directly.

It is enough to copy only `composer.lock` into the image — `vendor/` is not needed:

```dockerfile
COPY composer.lock ./

RUN set -eux; \
    version="$(jq -r '.packages[] | select(.name=="sconcur/sconcur") | .version' composer.lock | sed 's/^v//')"; \
    curl -fSL --connect-timeout 10 --retry 3 -4 \
      "https://github.com/sprust/sconcur/releases/download/v${version}/sconcur.so" \
      -o "$(php-config --extension-dir)/sconcur.so"; \
    echo "extension=sconcur.so" > /usr/local/etc/php/conf.d/docker-php-ext-sconcur.ini
```

In `composer.lock` the version may be stored with a `v` prefix (`v0.4.0`), so the
leading `v` is stripped (`sed 's/^v//'`) and added back in the URL (`v${version}`)
— otherwise it would become `vv0.4.0`. `jq` and `curl` are required in this build
layer.

## sconcur-status — check the installation

Reports whether the `sconcur` extension is loaded and whether its version matches
the version the package is built against. By default it prints a human-readable
report; with `--json` — a single machine-readable line.

```sh
vendor/bin/sconcur-status
#   sconcur status
#     extension installed:  yes
#     package version:      0.4.0
#     extension version:    0.4.0
#     ready:                yes

vendor/bin/sconcur-status --json
#   {"extension_installed":true,"package_version":"0.4.0","extension_version":"0.4.0","ready":true}
```

The extension must be enabled for the same process, otherwise the script cannot
see it:

```sh
php -d extension=./ext/sconcur.so vendor/bin/sconcur-status
```

JSON fields:

- `extension_installed` — whether the `sconcur` extension is loaded in the current
  process.
- `package_version` — the version the package is built against
  (`Extension::REQUIRED_EXTENSION_VERSION`).
- `extension_version` — the version of the loaded extension (`null` if not loaded).
- `ready` — `true` only when the extension is loaded and its version exactly
  matches the package version.

Return code: `0` when `ready=true`, otherwise `1` — a pipeline can branch without
parsing the output. The release CI is built on this: it gates the build on `ready`
and tags the release as `v<extension_version>`.

## sconcur-server — worker master

Starts and supervises a pool of worker processes (scaling across cores via
`SO_REUSEPORT`, restarting crashed ones, graceful shutdown). Commands:
`start` / `status` / `stop` / `reload`, all with a single `--configPath` flag
pointing to the master's JSON config.

```sh
vendor/bin/sconcur-server start --configPath=/app/master.json
```

Details — config parameters, restart policy, logging and graceful shutdown — are in
[Worker master](worker-master.md).
