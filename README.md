# flyokai/magento-amp-mate

> User docs → [`README.md`](README.md) · Agent quick-ref → [`CLAUDE.md`](CLAUDE.md) · Agent deep dive → [`AGENTS.md`](AGENTS.md)

> Async Magento cache backend powered by AMPHP — file-based caching that doesn't block the event loop.

A drop-in replacement for `Cm_Cache_Backend_File` that performs file I/O via `amphp/file`, protecting reads/writes with `ampFlock()` from [`flyokai/amp-mate`](../amp-mate/README.md).

## Features

- **`Rewrite\CmCacheBackendFile`** — async file cache backend, 100% protocol-compatible with the Magento cache front-ends
- **`MageCache\Data\AmpStreamReader`** — async stream parser for the metadata + data layout used by Magento cache files
- Optional `flock` via `ampFlock()` for safe concurrent access

## Installation

```bash
composer require flyokai/magento-amp-mate
```

## CmCacheBackendFile

`Rewrite\CmCacheBackendFile` extends Magento's `Cm_Cache_Backend_File` and replaces blocking file I/O with AMPHP equivalents:

- `_getCache()` — async read via `AmpStreamReader`
- `save()` — async write with optional `flock`
- `_clean()` — async recursive cleanup
- `_filePutContents()` — uses `Amp\File\openFile()` with optional locking
- `_fileGetContents()` — buffered read via `ampStreamBuffer()`

Wire it into Magento's cache configuration as you would any other cache backend implementation.

## AmpStreamReader

```php
use Flyokai\MagentoAmpMate\MageCache\Data\AmpStreamReader;

$reader = new AmpStreamReader();
$reader->parseStream($stream);

$meta = $reader->getMeta();   // first line: serialised metadata
$data = $reader->getData();   // remainder: cache payload
```

Layout: first line is serialised metadata, the rest is the cache data. The reader uses AMPHP's `Parser` class to keep state, so parsing is incremental.

## Gotchas

- **Mixed sync/async in `_clean()`** — uses `is_dir()` / `rmdir()` (sync) alongside async operations.
- **`AmpStreamReader` consumes parser state** — calling `getMeta()` before `getData()` advances the cursor; ordering matters.
- **Random tag-file compaction** — there's a 1% chance of rewriting tag files during merge.
- **`_getTagIds()` accepts multiple types** — string path, AmpFile, or resource. Caller must be unambiguous.
- **File locking is optional** — controlled by `$this->_options['file_locking']`. Race conditions if disabled.

## See also

- [`flyokai/amp-mate`](../amp-mate/README.md) — `ampFlock`, `ampOpenFile`, and friends
- [`flyokai/magento-dto`](../magento-dto/README.md) — Magento DTOs

## License

MIT
