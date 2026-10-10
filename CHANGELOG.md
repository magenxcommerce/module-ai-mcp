# Changelog

## [1.5.1](https://github.com/magenxcommerce/module-ai-mcp/compare/v1.5.0...v1.5.1) (2026-10-10)


### Bug Fixes

* use canRestore in system.xml so Magento accepts the config ([#24](https://github.com/magenxcommerce/module-ai-mcp/issues/24)) ([6e427a3](https://github.com/magenxcommerce/module-ai-mcp/commit/6e427a3b86f06a07867125376f1021d4ed5607a4))

## [1.5.0](https://github.com/magenxcommerce/module-ai-mcp/compare/v1.4.0...v1.5.0) (2026-10-06)


### Features

* add read-only log tools behind their own ACL resource ([8ce14d7](https://github.com/magenxcommerce/module-ai-mcp/commit/8ce14d7b2b6933711ce73c8ca4b0878a2aa62fc7))


### Bug Fixes

* Add log reading tools for AI agent diagnostics ([#22](https://github.com/magenxcommerce/module-ai-mcp/issues/22)) ([8ce14d7](https://github.com/magenxcommerce/module-ai-mcp/commit/8ce14d7b2b6933711ce73c8ca4b0878a2aa62fc7))

## [1.4.0](https://github.com/magenxcommerce/module-ai-mcp/compare/v1.3.2...v1.4.0) (2026-10-03)


### Features

* add quick search promotion tools ([2a7298b](https://github.com/magenxcommerce/module-ai-mcp/commit/2a7298b538b16e573d427876be25333c9b36defc))


### Bug Fixes

* Add quick search promotion management tools ([#20](https://github.com/magenxcommerce/module-ai-mcp/issues/20)) ([2a7298b](https://github.com/magenxcommerce/module-ai-mcp/commit/2a7298b538b16e573d427876be25333c9b36defc))

## [1.3.2](https://github.com/magenxcommerce/module-ai-mcp/compare/v1.3.1...v1.3.2) (2026-09-22)


### Bug Fixes

* Register tools as proxies to defer construction until needed ([#18](https://github.com/magenxcommerce/module-ai-mcp/issues/18)) ([79ee46e](https://github.com/magenxcommerce/module-ai-mcp/commit/79ee46e20aee43b0dbc956968bc728e94f81e319))


### Performance Improvements

* build tools lazily so a request constructs only what it uses ([79ee46e](https://github.com/magenxcommerce/module-ai-mcp/commit/79ee46e20aee43b0dbc956968bc728e94f81e319))

## [1.3.1](https://github.com/magenxcommerce/module-ai-mcp/compare/v1.3.0...v1.3.1) (2026-09-17)


### Bug Fixes

* Add tool annotations and expand tool catalog with 100+ new tools ([#16](https://github.com/magenxcommerce/module-ai-mcp/issues/16)) ([510647f](https://github.com/magenxcommerce/module-ai-mcp/commit/510647fdb353fddf468d9db3bfdec58ac70521ee))

## [1.3.0](https://github.com/magenxcommerce/module-ai-mcp/compare/v1.2.0...v1.3.0) (2026-09-14)


### Features

* add search_admin_users so assignment ids can be discovered ([#14](https://github.com/magenxcommerce/module-ai-mcp/issues/14)) ([fa94a66](https://github.com/magenxcommerce/module-ai-mcp/commit/fa94a66c0cec8dbda7991933d23885f5768e3de5))

## [1.2.0](https://github.com/magenxcommerce/module-ai-mcp/compare/v1.1.1...v1.2.0) (2026-09-13)


### Features

* add nine sales-document tools ([#12](https://github.com/magenxcommerce/module-ai-mcp/issues/12)) ([1df0518](https://github.com/magenxcommerce/module-ai-mcp/commit/1df0518991d9f221cf06dfa87f921d5db51dd6d2))

## [1.1.1](https://github.com/magenxcommerce/module-ai-mcp/compare/v1.1.0...v1.1.1) (2026-09-13)


### Bug Fixes

* advertise an argument-less tool's schema as an object ([#10](https://github.com/magenxcommerce/module-ai-mcp/issues/10)) ([483d87d](https://github.com/magenxcommerce/module-ai-mcp/commit/483d87d8b491fbfecd3043a003f420ad5e64bf55))

## [1.1.0](https://github.com/magenxcommerce/module-ai-mcp/compare/v1.0.1...v1.1.0) (2026-09-13)


### Features

* expand the MCP tool surface from 17 to 126 tools ([#8](https://github.com/magenxcommerce/module-ai-mcp/issues/8)) ([7664020](https://github.com/magenxcommerce/module-ai-mcp/commit/7664020c26160d7e97a6cf127782aaaa93e5378f))

## [1.0.1](https://github.com/magenxcommerce/module-ai-mcp/compare/v1.0.0...v1.0.1) (2026-08-14)


### Bug Fixes

* Add security hardening: endpoint grant, origin validation, audit logging ([#5](https://github.com/magenxcommerce/module-ai-mcp/issues/5)) ([f1c13ff](https://github.com/magenxcommerce/module-ai-mcp/commit/f1c13ff9ebd88f377be211fcde99b6983bf9e173))
* enforce the endpoint ACL grant and harden the config read path ([f1c13ff](https://github.com/magenxcommerce/module-ai-mcp/commit/f1c13ff9ebd88f377be211fcde99b6983bf9e173))

## 1.0.0 (2026-08-11)


### Miscellaneous Chores

* MagenX Commerce Magento 2 module ([3cb93b2](https://github.com/magenxcommerce/module-ai-mcp/commit/3cb93b2ea1f79acbded97aa9394a356643392d53))
* Magenxcommerce composer namespace ([ba30890](https://github.com/magenxcommerce/module-ai-mcp/commit/ba308906f52ac42bfdddc864e4cb165baf860d30))
