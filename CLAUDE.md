# flyokai/magento-amp-mate

> User docs → [`README.md`](README.md) · Agent quick-ref → [`CLAUDE.md`](CLAUDE.md) · Agent deep dive → [`AGENTS.md`](AGENTS.md)

Async Magento cache backend using AMPHP: AmpStreamReader for cache parsing, CmCacheBackendFile for async file-based caching.

See [AGENTS.md](AGENTS.md) for detailed module knowledge.

## Quick Reference

- **Cache backend**: `CmCacheBackendFile` extends Cm_Cache_Backend_File with async I/O
- **Stream parser**: `AmpStreamReader` for cache file metadata + data
- **Depends on**: flyokai/amp-mate (ampFlock, ampOpenFile, etc.)
