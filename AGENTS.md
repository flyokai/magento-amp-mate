# flyokai/magento-amp-mate

Magento-specific AMPHP helpers: async cache backend and stream parsing.

## Key Classes

### AmpStreamReader
`MageCache\Data\AmpStreamReader` — async stream parser for Magento cache files:
- Uses AMPHP Parser class for stateful parsing
- First line: serialized metadata
- Rest: cache data
- `getMeta()` and `getData()` with lazy-loading

### CmCacheBackendFile
`Rewrite\CmCacheBackendFile` — extends Magento's `Cm_Cache_Backend_File` with AMPHP async:
- `_getCache()` — async read with AmpStreamReader
- `save()` — async write with optional flock via `ampFlock()`
- `_clean()` — async recursive directory cleanup
- `_filePutContents()` — uses `Amp\File\openFile()` with optional locking
- `_fileGetContents()` — buffered read via `ampStreamBuffer()`

## Gotchas

- **Mixed sync/async in _clean()**: uses `is_dir()`/`rmdir()` (sync) alongside async operations
- **AmpStreamReader consumes state**: if `getMeta()` called first, parser state consumed
- **Random cache compaction**: 1% chance of rewriting tag files during merge
- **_getTagIds() accepts multiple types**: string path, AmpFile, or resource — type confusion possible
- **File locking optional**: `$this->_options['file_locking']` controls; race conditions if disabled
