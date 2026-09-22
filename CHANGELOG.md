# Changelog

All notable changes to this project will be documented in this file.

## [1.2.1](https://github.com/helsingborg-stad/municipio-clone/compare/v1.2.0...v1.2.1) (2026-09-22)


### Bug Fixes

* **import:** preserve artifact integrity during download ([466b417](https://github.com/helsingborg-stad/municipio-clone/commit/466b4178c27bdbcd56f21eb7981a487431182b9f))

## [1.2.0](https://github.com/helsingborg-stad/municipio-clone/compare/v1.1.7...v1.2.0) (2026-09-22)


### Features

* **cli:** report clone progress and failures ([ab65f54](https://github.com/helsingborg-stad/municipio-clone/commit/ab65f548544f2424f2ab024bf387711e0f1fd6b7))

## [1.1.7](https://github.com/helsingborg-stad/municipio-clone/compare/v1.1.6...v1.1.7) (2026-09-22)


### Bug Fixes

* **rest:** prevent buffer flush crash during download ([921e7b1](https://github.com/helsingborg-stad/municipio-clone/commit/921e7b13342b663e83090c4bd88dca08db37ffbe))

## [1.1.6](https://github.com/helsingborg-stad/municipio-clone/compare/v1.1.5...v1.1.6) (2026-09-22)


### Bug Fixes

* **clone:** stream artifacts throughout clone pipeline ([d8faeb0](https://github.com/helsingborg-stad/municipio-clone/commit/d8faeb01da19b2eb4d3dcbd6ae1196a3cb4fb7bc))

## [1.1.5](https://github.com/helsingborg-stad/municipio-clone/compare/v1.1.4...v1.1.5) (2026-09-22)


### Bug Fixes

* **export:** stream artifact encryption to avoid memory exhaustion ([13c68f6](https://github.com/helsingborg-stad/municipio-clone/commit/13c68f69e0a6519c16728af3d94e3301c68b3dea))

## [1.1.4](https://github.com/helsingborg-stad/municipio-clone/compare/v1.1.3...v1.1.4) (2026-09-22)


### Bug Fixes

* **export:** stream SQL export generation to avoid memory exhaustion ([8618d8d](https://github.com/helsingborg-stad/municipio-clone/commit/8618d8db7863b769587b4870ec046d9868a9ecf2))

## [1.1.3](https://github.com/helsingborg-stad/municipio-clone/compare/v1.1.2...v1.1.3) (2026-09-21)


### Bug Fixes

* **export:** extend runtime limit during export to prevent timeout 500s ([7e8923d](https://github.com/helsingborg-stad/municipio-clone/commit/7e8923da0e03dca01e71832e0a0472771c14a42e))

## [1.1.2](https://github.com/helsingborg-stad/municipio-clone/compare/v1.1.1...v1.1.2) (2026-09-21)


### Bug Fixes

* **cli:** forward overwrite confirmation arguments ([c477cb0](https://github.com/helsingborg-stad/municipio-clone/commit/c477cb0995cd169d866bbe751780c2b8f13da91e))

## [1.1.1](https://github.com/helsingborg-stad/municipio-clone/compare/v1.1.0...v1.1.1) (2026-09-21)


### Miscellaneous Chores

* drop support for php 8.2 ([dc2d2dd](https://github.com/helsingborg-stad/municipio-clone/commit/dc2d2dd08d582a5fbc645973a76aff40322c298a))

## [1.1.0](https://github.com/helsingborg-stad/municipio-clone/compare/v1.0.0...v1.1.0) (2026-09-21)


### Features

* **clone:** add sanitized export endpoint and clone command ([2fe5208](https://github.com/helsingborg-stad/municipio-clone/commit/2fe5208d09fc7784987e677274b17fe3631b8d93))


### Bug Fixes

* **clone:** address review hardening feedback ([2713784](https://github.com/helsingborg-stad/municipio-clone/commit/2713784a64c9892d5b66d5fde8c813fe59af244b))
* **clone:** harden export and import flows ([33fda10](https://github.com/helsingborg-stad/municipio-clone/commit/33fda10ceb6c656bb97207cb5b77a213f426125b))
* **clone:** harden transport and artifact handling ([d4837e0](https://github.com/helsingborg-stad/municipio-clone/commit/d4837e067d6f31243044eaf3463fa537f6efaa64))
* **clone:** normalize request and import edge cases ([9fa83b5](https://github.com/helsingborg-stad/municipio-clone/commit/9fa83b5cbb1b9bdd02d6aa936862dacd64d800a5))
* **clone:** refine multisite import cleanup behavior ([b2a1109](https://github.com/helsingborg-stad/municipio-clone/commit/b2a1109f69a4b041a85cc420da1b1797b71bf1d9))
* **clone:** require explicit api key argument ([2d9df83](https://github.com/helsingborg-stad/municipio-clone/commit/2d9df83141a34a93f9d391531af9621a9eec04c0))
* **clone:** tighten crypto and remote download safeguards ([33c760a](https://github.com/helsingborg-stad/municipio-clone/commit/33c760a7888709f5822db125307787ecce2c8e5e))
* **clone:** tighten remapping and lookup behavior ([a02cb7d](https://github.com/helsingborg-stad/municipio-clone/commit/a02cb7daac87ee2602f6f5842d50ad474ededddb))

## [1.0.0] - 2026-09-21

### Added

- Initial sanitized export API and `wp municipio clone` workflow implementation.
