# Changelog

All notable changes to this project will be documented in this file.

## [1.6.5](https://github.com/helsingborg-stad/municipio-clone/compare/v1.6.4...v1.6.5) (2026-10-21)


### Bug Fixes

* **batch:** run each target in isolation ([63a5085](https://github.com/helsingborg-stad/municipio-clone/commit/63a5085afa640ddd79ac601ab6477a44ededca17))
* improve sql import speed ([7ea121d](https://github.com/helsingborg-stad/municipio-clone/commit/7ea121d79d149e4a422c372b5c527880e81a80e7))

## [1.6.4](https://github.com/helsingborg-stad/municipio-clone/compare/v1.6.3...v1.6.4) (2026-10-20)


### Bug Fixes

* **import:** keep new clone uploads local ([#47](https://github.com/helsingborg-stad/municipio-clone/issues/47)) ([23ad78d](https://github.com/helsingborg-stad/municipio-clone/commit/23ad78d50a679cecaabcbc6fca069cc06af5aa30))

## [1.6.3](https://github.com/helsingborg-stad/municipio-clone/compare/v1.6.2...v1.6.3) (2026-10-19)


### Bug Fixes

* **import:** restore GUIDs in legacy export artifacts ([#45](https://github.com/helsingborg-stad/municipio-clone/issues/45)) ([8709719](https://github.com/helsingborg-stad/municipio-clone/commit/870971931d9ac031a749c4301bb9b89fb8bbcafe))

## [1.6.2](https://github.com/helsingborg-stad/municipio-clone/compare/v1.6.1...v1.6.2) (2026-10-19)


### Bug Fixes

* **export:** preserve GUIDs during URL placeholder replacement ([#43](https://github.com/helsingborg-stad/municipio-clone/issues/43)) ([bc0f6b5](https://github.com/helsingborg-stad/municipio-clone/commit/bc0f6b55e542396bbf1018f59d852ce40e908c91))

## [1.6.1](https://github.com/helsingborg-stad/municipio-clone/compare/v1.6.0...v1.6.1) (2026-10-19)


### Bug Fixes

* **import:** forward allow-root to streamed WP-CLI import ([#41](https://github.com/helsingborg-stad/municipio-clone/issues/41)) ([5437462](https://github.com/helsingborg-stad/municipio-clone/commit/543746248d130a58800bd28d6825cd41aa1a4d8b))

## [1.6.0](https://github.com/helsingborg-stad/municipio-clone/compare/v1.5.4...v1.6.0) (2026-10-19)


### Features

* **clone:** report progress during SQL remap and import ([#39](https://github.com/helsingborg-stad/municipio-clone/issues/39)) ([0a74ddb](https://github.com/helsingborg-stad/municipio-clone/commit/0a74ddbd70c52f028df1c234e6bbd235c4265918))

## [1.5.4](https://github.com/helsingborg-stad/municipio-clone/compare/v1.5.3...v1.5.4) (2026-10-19)


### Bug Fixes

* **clone:** flush target rewrite rules after import ([#37](https://github.com/helsingborg-stad/municipio-clone/issues/37)) ([e5cc98d](https://github.com/helsingborg-stad/municipio-clone/commit/e5cc98d7df24b21eb4b6ea93d00ea37103baf9c7))

## [1.5.3](https://github.com/helsingborg-stad/municipio-clone/compare/v1.5.2...v1.5.3) (2026-10-19)


### Bug Fixes

* avoid empty artifact downloads on LiteSpeed ([#27](https://github.com/helsingborg-stad/municipio-clone/issues/27)) ([320399e](https://github.com/helsingborg-stad/municipio-clone/commit/320399e9be771efc957e2913cbcb639ee8f24468))

## [1.5.2](https://github.com/helsingborg-stad/municipio-clone/compare/v1.5.1...v1.5.2) (2026-10-19)


### Bug Fixes

* user friendly error messages ([#34](https://github.com/helsingborg-stad/municipio-clone/issues/34)) ([be3749d](https://github.com/helsingborg-stad/municipio-clone/commit/be3749df02fc657372a30a843e615d73f2a25f4c))

## [1.5.1](https://github.com/helsingborg-stad/municipio-clone/compare/v1.5.0...v1.5.1) (2026-10-19)


### Bug Fixes

* preserve distinct home and siteurl values during clones ([#33](https://github.com/helsingborg-stad/municipio-clone/issues/33)) ([7da8177](https://github.com/helsingborg-stad/municipio-clone/commit/7da81779803e2c31ec736fb8c5a68de4f2e56418))
* **storage:** keep export artifacts immutable ([#30](https://github.com/helsingborg-stad/municipio-clone/issues/30)) ([c43d8a9](https://github.com/helsingborg-stad/municipio-clone/commit/c43d8a9db068a809fbe43665fd7d0d9de5bbed9d))

## [1.5.0](https://github.com/helsingborg-stad/municipio-clone/compare/v1.4.4...v1.5.0) (2026-10-05)


### Features

* allow using constants for environment variables in clone mapping ([094da93](https://github.com/helsingborg-stad/municipio-clone/commit/094da93900700aae249d0550a93a6775351cbad3))


### Miscellaneous Chores

* add .vscode extensions ([7245637](https://github.com/helsingborg-stad/municipio-clone/commit/7245637a9c1431186f415154280ea6f507cb50d4))

## [1.4.4](https://github.com/helsingborg-stad/municipio-clone/compare/v1.4.3...v1.4.4) (2026-09-30)


### Bug Fixes

* unhandled exception on false passed to unserialization ([b65e186](https://github.com/helsingborg-stad/municipio-clone/commit/b65e186d07a0cde78ae9acdd19efee690cbc873f))

## [1.4.3](https://github.com/helsingborg-stad/municipio-clone/compare/v1.4.2...v1.4.3) (2026-09-28)


### Bug Fixes

* improve feedback ([a10e59c](https://github.com/helsingborg-stad/municipio-clone/commit/a10e59cbba09c45cc8168b8a9d17db0a3612320a))

## [1.4.2](https://github.com/helsingborg-stad/municipio-clone/compare/v1.4.1...v1.4.2) (2026-09-24)


### Bug Fixes

* **import:** correctly rewrite remote media urls to the source's actual media domain ([4cb5b75](https://github.com/helsingborg-stad/municipio-clone/commit/4cb5b75dd3f31b7d001df538eb0dccfb94dea35f))

## [1.4.1](https://github.com/helsingborg-stad/municipio-clone/compare/v1.4.0...v1.4.1) (2026-09-22)


### Bug Fixes

* **cli:** move batch clone to top-level command ([91fa8bc](https://github.com/helsingborg-stad/municipio-clone/commit/91fa8bcbf794e0f90c3ee5622f79b332e53eea95))

## [1.4.0](https://github.com/helsingborg-stad/municipio-clone/compare/v1.3.1...v1.4.0) (2026-09-22)


### Features

* **cli:** add batch clone command ([a73c44c](https://github.com/helsingborg-stad/municipio-clone/commit/a73c44c20941a639fa3e40399f631590c2538111))

## [1.3.1](https://github.com/helsingborg-stad/municipio-clone/compare/v1.3.0...v1.3.1) (2026-09-22)


### Bug Fixes

* **import:** preserve multisite remote media urls ([a039a3b](https://github.com/helsingborg-stad/municipio-clone/commit/a039a3ba35c57a4c4114c054602ad1f9a8da9ae4))

## [1.3.0](https://github.com/helsingborg-stad/municipio-clone/compare/v1.2.6...v1.3.0) (2026-09-22)


### Features

* **cli:** keep remote media urls ([5327820](https://github.com/helsingborg-stad/municipio-clone/commit/53278205963a616aa2dbd9cf19fb6954cb6a62a8))

## [1.2.6](https://github.com/helsingborg-stad/municipio-clone/compare/v1.2.5...v1.2.6) (2026-09-22)


### Bug Fixes

* **import:** discover target tables without shell escaping ([97fc488](https://github.com/helsingborg-stad/municipio-clone/commit/97fc4884597f3e82ec8643698acaf0db2d4faad4))

## [1.2.5](https://github.com/helsingborg-stad/municipio-clone/compare/v1.2.4...v1.2.5) (2026-09-22)


### Bug Fixes

* **import:** avoid target bootstrap during replacement ([c825ff5](https://github.com/helsingborg-stad/municipio-clone/commit/c825ff5b6d1029453333aeb2d09721d1d794fbaf))

## [1.2.4](https://github.com/helsingborg-stad/municipio-clone/compare/v1.2.3...v1.2.4) (2026-09-22)


### Bug Fixes

* **import:** target the selected multisite blog ([72abe7e](https://github.com/helsingborg-stad/municipio-clone/commit/72abe7e3a96f973a6f4ec25f97dbabb6855a5e1f))

## [1.2.3](https://github.com/helsingborg-stad/municipio-clone/compare/v1.2.2...v1.2.3) (2026-09-22)


### Bug Fixes

* **import:** normalize target site URLs ([77a30b4](https://github.com/helsingborg-stad/municipio-clone/commit/77a30b4ca693e8e0f94cef9a1fbe819bbdf341f8))

## [1.2.2](https://github.com/helsingborg-stad/municipio-clone/compare/v1.2.1...v1.2.2) (2026-09-22)


### Bug Fixes

* **import:** download large artifacts in chunks ([2a65425](https://github.com/helsingborg-stad/municipio-clone/commit/2a6542504ad41438748997bccc39b7b0a54a2fa0))

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
