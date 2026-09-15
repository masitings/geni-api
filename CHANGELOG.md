# Changelog

All notable changes to `masitings/geni-api` will be documented in this file.

## [Unreleased]

### Fixed

- **`geni:export`/`geni:check` `--path` resolution**: both commands always ran the given path through `base_path()` even when it was already absolute, silently doubling/mangling the destination (e.g. writing into `vendor/orchestra/testbench-core/laravel/...` when run under Testbench instead of the intended path). Now mirrors `geni:mcp`'s existing guard: an absolute path (or one that already exists) is used as-is.
- **`InferenceDiagnostic` embedded absolute filesystem paths** (e.g. a developer's home directory) into the OpenAPI document's `x-geni-unresolved` extension. Since these paths are baked into the generated document, a spec produced on one machine would never match the same spec regenerated on another (including CI), making `geni:check` report false drift for content that hadn't actually changed. Diagnostic file paths are now relativized against the current working directory before being serialized.
- `geni:check` compared the generated and committed OpenAPI documents with strict `===` on decoded PHP arrays, which is order-sensitive for JSON objects. Comparison now recursively sorts object keys (canonicalizes) before comparing while preserving list-array order, which is semantically significant, so key-emission order alone can no longer produce a false positive.

## [1.0.0] - 2026-09-15

### Added

- Multi-document routing and interactive API version switcher dropdown in Blade docs UI:
  - `GeniServiceProvider` registers dedicated versioned routes for each entry in `config('geni.apis')` (e.g. `/docs/api/v1`, `/docs/api/v1.json`, `/docs/api/v1/mcp`).
  - `RouteDiscoverer::discover($apiName)` and `BuildsInfoOptions::buildInfoOptions($apiName)` scope discovery and OpenAPI metadata per named version.
  - `blade-docs.blade.php` renders an interactive version switcher dropdown in the sidebar header when `> 1` APIs are configured, switching specifications in-place and updating browser history via `history.pushState()`.
  - Single-document setups (`config('geni.apis') => []`) retain standard zero-overhead behavior without dropdowns (100% backward compatible).
- Optional sidebar promo card (`config('geni.promo')`): disabled by default, lets a host app render a small branded banner (icon, title, subtitle, link) in the docs sidebar. Content-neutral — no branding shipped in the package itself.

### Fixed

- Mobile nav drawer and mobile "Try It" slide-over were missing several desktop-only sidebar features (API version switcher, endpoint search, promo card, footer credit, and full auth/param/body request form) — both surfaces now share the same partials as desktop so mobile has full parity.
- Response body and response example panels now use a fixed dark background instead of one that blended into their dark-mode container.
- Response status badges and the version switcher dropdown no longer overflow on narrow screens or with many entries.
- DocsFormAuth middleware now returns 401 JSON (instead of an HTML redirect) for unauthenticated requests to versioned API JSON spec paths (`docs/api/{name}.json`) configured via `geni.apis`.
- Version switcher dropdown now displays the configured `title` as-is instead of appending a `(v{version})` suffix.

## [0.10.0] - 2026-09-14

### Added

- Native Model Context Protocol (MCP) real execution server (`php artisan geni:mcp:serve` and `POST /docs/api/mcp`):
  - `Geni\Laravel\Mcp\McpProtocolHandler`: native JSON-RPC 2.0 protocol engine handling `initialize`, `notifications/initialized`, `ping`, `tools/list`, and `tools/call`.
  - `Geni\Laravel\Mcp\McpToolExecutor`: live HTTP execution engine dispatching real requests to host application endpoints via Laravel HTTP client (`Http::class`), formatting path variables, query parameters, headers, JSON body payloads, and authentication tokens (Bearer / API key).
  - Returns compliant `CallToolResult` text envelopes with status line, formatted JSON body, and `isError: true` on 4xx/5xx or network errors.
  - Zero external dependencies: pure JSON-RPC 2.0 and Laravel standard library.
  - `config('geni.mcp.execution')`: `base_url`, `timeout`, `default_bearer_token`, `default_api_key`, `api_key_header`, `schemes` for configuring how the execution server authenticates against the host app's own API.
  - README.md and DOCUMENTATION.md updated with Claude Desktop / Claude Code / remote HTTP client setup examples.

## [0.9.0] - 2026-09-14

### Added

- Model Context Protocol (MCP) tool manifest generator (`php artisan geni:mcp` command and `GET /docs/api/mcp` endpoint): automatically converts OpenAPI specifications into MCP tool definitions (`tools/list` schema), complete with self-contained dereferenced `inputSchema`s, `^[a-zA-Z0-9_-]{1,64}$` sanitized tool naming, invocation metadata (`_meta`), and ready-to-paste client configurations for AI agents (Claude Code, Cursor, Windsurf, Claude Desktop).
- Form-based documentation authentication mode (`docs_auth.mode => 'form'`): protects `/docs/api` and `/docs/api.json` with a dedicated HTML login form (`resources/views/docs-login.blade.php`), isolated session storage (`geni_docs_authenticated`), CSRF protection, and a "Sign Out" button in the docs sidebar footer without requiring native browser Basic Auth modals. Unauthenticated requests to `/docs/api.json` return a clean 401 JSON response.
- Request Code sample dropdown now offers Node.js/Axios, Python/Requests, Ruby/Net::HTTP, and Go/net/http in addition to cURL, JavaScript/Fetch, and PHP/Guzzle — each with matching Prism syntax highlighting.
- Sidebar can now be collapsed/expanded via a header button, with an "auto-collapse" toggle that hides it automatically after selecting an endpoint. Both states persist via `localStorage`.
- Sidebar footer now shows the installed `masitings/geni-api` package version, resolved via `Composer\InstalledVersions` (no new dependency).
- `README.md` and a new comprehensive `DOCUMENTATION.md` covering architecture, every config key, all `Geni\Laravel\Attributes\*`, PHPDoc annotation support, custom renderers, and Artisan commands.

### Changed

- Removed the top navigation header entirely; the docs title and dark/light toggle now live in the sidebar, which is also visually "floated" as an inset rounded card with its own border/shadow instead of a flush, edge-to-edge panel.
- Card headers (Security Requirements, Parameters, Request Body, Response Example) now share one consistent title style and a tinted background, and each got a matching icon.
- Response section (status selector, schema table, example) is fully merged into the Response Example card; the standalone "Responses" section and duplicate "Path Parameters" heading were removed.
- Accent color switched from emerald to sky across the UI; content cards no longer show a border in dark mode but keep a thin one in light mode for contrast against the page background.
- Docs login page (`docs-login.blade.php`) restyled to match the main docs design system instead of a generic templated login screen.

### Fixed

- Code panels (Request Code, Response Example, Try It result) used the same background color as the page in dark mode, making them visually disappear; now consistently distinct.
- Tailwind's `divide-y` combined with Alpine's `<template x-for>` produced a phantom top border on the first row of several tables, because the `<template>` tag itself counts as a preceding sibling for the `> * + *` selector; replaced with an explicit `border-b last:border-b-0` per row.
- The Sample/Try It workbench card's `overflow-hidden` clipped its own language-selector dropdown menu; scoped the rounding/clipping to the header and tab-content sections individually instead of the whole card.
- `Geni\Laravel\Middleware\DocsFormAuth` middleware.
- `Geni\Laravel\Controllers\DocsAuthController` handling login form, credentials validation, session regeneration, and logout.

## [0.8.0] - 2026-09-14

### Added

- `Geni\Laravel\Renderers\BladeRenderer`: A new built-in, standalone documentation renderer powered by Blade, Tailwind CSS (Play CDN), and Alpine.js. Provides a modern developer documentation portal complete with live search, sticky developer workbench, reactive code samples (cURL, Fetch, Guzzle), interactive Try It console with live HTTP execution and token management, and theme toggling (Dark/Light mode) without requiring React, Vite, or any frontend build step.
- Template `resources/views/blade-docs.blade.php` shipped with the package.
- `DocumentationController::resolveRenderer()` now recognizes `'blade'` and `'default'`, setting `BladeRenderer` as the default renderer.
- Real syntax highlighting via Prism.js (CDN) on Request Code samples (bash/JavaScript/PHP), Response Example, and Try It response bodies (JSON) — including a live-highlighted, still-editable overlay on the Try It request body editor.
- Sidebar tag groups are collapsed by default and behave as an accordion (opening one group closes the others; only the group containing the selected endpoint stays open).
- "Schema" / "Raw" tab switcher on the Response Example card, styled as a segmented control matching the Sample/Try It tabs.

### Changed

- Sidebar method indicator switched from a bordered badge to a plain colored label, right-aligned next to the endpoint summary.
- The former standalone "Responses" section (status pill selector, response schema table, description) was merged into the Response Example card and relocated from the developer workbench (right column) into the main content column, alongside Request Body.
- Default accent color switched from emerald (green) to sky (blue) across focus rings, buttons, status pills, and the `GET` method color; the sidebar active-item indicator bar is now neutral (slate/white) rather than colored.
- Removed the server origin badge and "OpenAPI Spec" link from the top navigation bar.
- Form fields (search input, Try It parameter/token inputs and labels) no longer force a monospace font — monospace is now reserved for actual code/JSON content.

### Fixed

- Whitespace between `<pre>` and `<code>` tags in code panels (Request Code, Response Example) produced misaligned first-line indentation; markup is now written without intervening whitespace.

### Removed

- `Geni\Laravel\Renderers\ElementsRenderer` and template `resources/views/elements.blade.php` (Stoplight Elements) removed in favor of native `BladeRenderer`.

## [0.7.0] - 2026-09-14

### Fixed

- `config('geni.renderer')` was completely ignored — `DocumentationController::resolveRenderer()` was hardcoded to always return `ElementsRenderer` regardless of config, making it impossible to plug in a custom `Geni\Laravel\Renderer` implementation as the README always claimed was supported ("implement `Geni\Laravel\Renderer` for your own"). Now resolves `'elements'` to the built-in renderer, or any fully-qualified class name implementing `Renderer` via the container, falling back to `ElementsRenderer` for an unset/invalid value.

## [0.6.7] - 2026-09-14

### Fixed

- Description/prose text and code sample panels (Request Sample, Response Example) stayed near-invisible in dark mode: the code panels use their own syntax-highlighter theme independent of Stoplight's `--color-*` variables, and some description text uses Tailwind-style utility classes rather than those variables. Added broad, best-effort overrides (`elements-api *` text color, `pre`/`code`/CodeMirror/token background+text, `sl-text-muted`/`sl-text-light` utility classes). Still unofficial — Stoplight Elements ships no theming API, so there's no guaranteed-stable selector list; this may need adjustment on a Stoplight Elements upgrade.

## [0.6.6] - 2026-09-14

### Fixed

- Confirmed against Stoplight Elements' actual source (`web-components/components.ts`'s full prop list) that it has **no built-in dark mode or theming API at all** — the `darkMode` attribute from 0.6.5 doesn't exist in the shipped component; it was only ever a GitHub feature request, never implemented. `dark_mode` now works by overriding Stoplight's own documented CSS custom properties (`--color-canvas`, `--color-text`, `--color-border-*`, etc.) directly, which is the only mechanism that actually exists. This is inherently unofficial and may need adjustment if a future Stoplight Elements version renames these variables.

## [0.6.5] - 2026-09-14

### Fixed

- Dark mode used a guessed `sl-dark` CSS class, which isn't Stoplight Elements' actual mechanism. Confirmed via Stoplight's own GitHub (stoplightio/elements#1439) that the real, working mechanism is the `darkMode` boolean attribute directly on `<elements-api>` (`<elements-api darkMode />`). Switched to that.

## [0.6.4] - 2026-09-14

### Fixed

- Dark mode (0.6.3) applied the `sl-dark` class only to `<html>`, which some Stoplight Elements builds don't pick up reliably. Now applies the class to `<html>`, `<body>`, and the `<elements-api>` element itself, plus an inline dark background on `<body>` as a visible fallback regardless of which ancestor the component actually keys off of.

## [0.6.3] - 2026-09-14

### Added

- `config('geni.renderers.elements.dark_mode')`, defaulting to `true` — Stoplight Elements now renders in dark mode out of the box, via the `sl-dark` class on `<html>`. Set to `false` for light mode.

## [0.6.2] - 2026-09-14

### Added

- `#[BodyParameter]` (and other Group 2 attributes) can now be declared on a Form Request's own `rules()` method instead of the controller action — Geni resolves the Form Request class from the action's parameter type hints (mirroring `ValidationRuleExtractor`'s own resolution) and reads attributes from there too, merging alongside anything declared on the action itself. Keeps per-field documentation next to the validation rules it describes.

## [0.6.1] - 2026-09-14

### Fixed

- `#[BodyParameter]` was parsed by `AttributeAnnotationReader` but never actually applied anywhere: `mergeNonPathParameterAttributes()` explicitly skipped `in: 'body'`, and `mergeParameterAttributes()` only ever ran for path parameters. Setting `description`/`example`/`format`/`default`/`infer: false` on a `#[BodyParameter]` had no effect on the generated request body schema. Added `mergeBodyParameterAttributes()`, which merges into the request body's per-field `properties` schema (the correct target -- a body field is a JSON Schema property, not a top-level OpenAPI `Parameter` object like path/query/header fields are).

## [0.6.0] - 2026-09-14

### Added

- `config('geni.title')`, `description`, `version`, `terms_of_service`, `contact.*`, `license.*` now populate the OpenAPI `info` object (title, description, version, contact, license), which the docs UI's "Overview" page displays — previously only `title` (hardcoded to `app.name`) and a hardcoded `version: '1.0.0'` were ever set, so the Overview page had no description/contact/license regardless of what a user configured (there was no config for them at all).
- `DocumentAssembler::assemble()` now accepts `terms_of_service`/`contact`/`license` in `$infoOptions`, populating `Geni\Inference\Document\Info`'s existing (previously unused) `termsOfService`/`contact`/`license` fields.
- New `BuildsInfoOptions` trait, shared by all four `geni:*` commands and `DocumentationController`, so `info` population is consistent everywhere instead of four slightly different hardcoded arrays.

## [0.5.1] - 2026-09-14

### Added

- Every documented `<elements-api>` (Stoplight Elements) option is now wired through `config('geni.renderers.elements')`: `layout`, `router`, `base_path`, `logo`, `hide_try_it`, `hide_schemas`, `hide_internal`, `hide_export`, `try_it_credentials_policy`, `try_it_cors_proxy`, `js_url`/`css_url` (pin a version or self-host), `custom_css`, and a generic `attributes` passthrough for any future Stoplight prop not yet wired explicitly. Previously only `layout` was configurable — everything else required publishing and editing the view directly.

## [0.5.0] - 2026-09-14

### Removed

- `Geni\Laravel\Renderers\ScalarRenderer` and its Blade view. [Stoplight Elements](https://stoplight.io/open-source/elements) (`elements`) is now the only built-in renderer and the default `config('geni.renderer')` value. The `Geni\Laravel\Renderer` interface is unchanged — implement it for any other renderer (Redoc, a custom UI, etc.).

### Changed

- `config('geni.renderer')` default changed from `'scalar'` to `'elements'`. Projects that explicitly set `renderer` to `'scalar'` need to switch to `'elements'` or supply their own `Renderer` implementation.

## [0.4.1] - 2026-09-14

### Added

- `docs_json_path` / `docs_ui_path` to `config/geni.php` — these were already readable (with a hardcoded fallback) but never listed in the published config, so users had no way to discover they were customizable.

### Documentation

- README rewritten with a full config reference (every key in `config/geni.php`), a dedicated Security & authentication section (single scheme, per-middleware schemes, multi-layer AND semantics, `docs_auth` vs security scheme distinction), and a complete PHPDoc/attribute reference with constructor signatures.

## [0.4.0] - 2026-09-14

### Added

- Multi-layer security requirements: a route matched by more than one `middleware_security_schemes` rule (e.g. `auth:sanctum` AND a custom `api-key` middleware) now documents `security: [{schemeA: [], schemeB: []}]` — one requirement object listing every applicable scheme, which OpenAPI/Scalar/Elements interpret as AND (all required together), not a route documented with only its first-matched scheme.

## [0.3.3] - 2026-09-14

### Fixed

- `DocumentationController` (serving the live `/docs/api` and `/docs/api.json` routes) built its own inline `securityConfig` instead of using the `BuildsSecurityConfig` trait added in 0.3.1 — so `middleware_security_schemes` only ever applied to `geni:export`'s CLI output, never to the actual docs pages. Both now share the same config-building logic.
- `/docs/api.json` never actually served from cache: the cache-read branch in `generateDocument()` built an unused `OpenApiDocument` and discarded it, always falling through to full regeneration. The JSON endpoint now returns the cached response body directly on a cache hit.

## [0.3.2] - 2026-09-14

### Fixed

- Class-level PHPDoc (`@tags`, `@deprecated`, `@mixin`, etc.) was silently ignored — `DocumentAssembler` hardcoded the parsed class docblock to an empty array instead of actually parsing the controller class's own doc comment. `ActionAst` now carries the declaring class's doc comment so it can be parsed.
- `@response ShortClassName` (a short name resolved via the file's own `use` imports, as Scramble supports) failed to resolve to a `$ref` and silently fell back to `type: string`. Resolution now checks the action's `use` import map before falling back to the raw string.

## [0.3.1] - 2026-09-14

### Added

- `config('geni.middleware_security_schemes')`: document different middleware with different OpenAPI security schemes (e.g. `auth:sanctum` → bearer token, a custom API-key guard → `apiKey`), instead of one global scheme applied to everything matched by `middleware_security`. Checked in order, first match wins, before falling back to `security_scheme`.

## [0.3.0] - 2026-09-14

### Restored

- `config('geni.docs_auth')` and `Geni\Laravel\Middleware\BasicAuthDocs` (briefly removed in 0.2.1) — gates the docs UI/JSON routes behind a browser-native HTTP Basic Auth sign-in prompt. Distinct from Scalar's in-UI Authentication panel, which is for testing the API itself, not for restricting who can view the documentation page.

## [0.2.0] - 2026-09-14

### Added

- `config('geni.docs_auth')` and `Geni\Laravel\Middleware\BasicAuthDocs`: protect the docs UI/JSON routes with a browser-native HTTP Basic Auth prompt (`GENI_DOCS_USERNAME`/`GENI_DOCS_PASSWORD` env vars), independent of the host app's own user/guard system.

## [0.1.1] - 2026-09-14

### Fixed

- `GeniServiceProvider` set an incorrect base path, causing `config('geni.*')` to silently resolve to `null`, `vendor:publish --tag=geni-config` to publish nothing, and package views to fail to load in any real host application. Only unnoticed until now because the package's own Testbench-based tests never exercised `vendor:publish`/config-merge through a real host app's install path.

## [0.1.0] - 2026-09-13

Initial public release of Geni: a static-analysis OpenAPI documentation generator for Laravel without application booting or database connections.

### Added

#### Milestone 1: Schema Reader (`Geni\SchemaReader`)
- Offline AST parsing of Laravel migrations (`nikic/php-parser`).
- Full Blueprint method mapping to normalized `ColumnType` enum.
- Table mutations (`dropColumn`, `renameColumn`, `->change()`), drops, and renames.
- Macro expansions (`id`, `timestamps`, `morphs`, `foreignIdFor`, `softDeletes`, etc.).
- Multi-connection schema separation (`Schema::connection('tenant')`).
- `schema:dump` detection and warnings.
- Deterministic cache key derivation based on migration paths and file modification times.
- Enforced framework-free boundary (`not->toUse(['Illuminate', 'Geni\Laravel'])`).

#### Phase 3: Generator Skeleton
- `Geni\Laravel\GeniServiceProvider` with configuration publishing and command registration.
- Route discovery via prefix matching (`api`) or custom route resolver closures.
- Route action resolution and AST acquisition without user code execution.
- Path parameter typing inferred from migration-derived database schemas.
- Basic request body extraction for inline `$request->validate([...])` literal arrays.
- Single-`JsonResource` response schema inference.
- In-memory OpenAPI 3.1.0 document model with deterministic serialization.
- `ScalarRenderer` serving interactive Scalar UI (`@scalar/api-reference`) at `/docs/api`.
- `geni:export` command for exporting the specification.
- Validation against official OpenAPI 3.1.0 JSON Schema meta-schema (`opis/json-schema`).

#### Phase 4: Inference Coverage
- Form Request extraction: statically parses `rules()` on `FormRequest` action parameters.
- Extraction from `Validator::make($data, $rules)` and `validator()` helpers.
- Full validation rule mapping: `array`, `date`, `date_format`, `size`, `between`, `regex`, `uuid`, `ulid`, `file`, `image`.
- Multipart request promotion when `file` or `image` rules are present.
- Static enum resolution for `Rule::enum` and `Enum` rule classes (`BackedEnumParser`).
- Offline `exists` rule resolution via `SchemaReader` with zero database queries.
- Extension points: `RuleTransformer`, `FullRuleSetTransformer` (`ConfirmedRuleTransformer`), and `ExceptionToResponse`.
- Request accessor extraction for `$request->integer()`, `string()`, `boolean()`, and `float()`.
- Rich response inference: `Resource::collection()`, paginator envelopes (`LengthAwarePaginator`, `Paginator`, `CursorPaginator`), plain Eloquent models, backed enums, plain PHP DTOs, and `response()->json([...])`.
- Error response inference: 422 (validation), 403 (authorization/Gate), 404 (model binding), abort codes, and `@throws` exception status mappings.
- Reusable schema registration under `components.schemas` with `$ref` pointers.

#### Phase 5: Scramble-Compatible Annotations
- PHPDoc parsing (`phpstan/phpdoc-parser`): `@response`, `@status`/`@body`, `@description`, `@var`, `@example`, `@format`, `@default`, `@query`, `@ignoreParam`, `@hidden`, `@deprecated`/`@notDeprecated`, `@operationId`, `@requestMediaType`, `@tags`, `@throws`, `@unauthenticated`, and `@mixin`/`@property` (Resource backing model resolution).
- PHP 8 attributes (`Geni\Laravel\Attributes\*`):
  - Group 1 (Markers): `#[ExcludeRouteFromDocs]`, `#[ExcludeAllRoutesFromDocs]`, `#[Hidden]`, `#[IgnoreParam]`, `#[IgnoreResponse]`.
  - Group 2 (Parameters): `#[QueryParameter]`, `#[HeaderParameter]`, `#[PathParameter]`, `#[BodyParameter]`, `#[CookieParameter]` (with `infer: false` escape hatch).
  - Group 3 (Metadata): `#[Endpoint]`, `#[Group]`, `#[SchemaName]`, `#[Response]`, `#[Header]`, `#[Example]`, `#[Api]`.
- Explicit `AnnotationMerger` respecting `infer: false` overrides.
- Explicit `ResponseTypeResolver` implementing priority rules (`@response` > declared > inferred).
- Pre-merge exclusion filtering.

#### Phase 6: Production Readiness
- `geni:analyze` command listing all unresolved inference diagnostics as human table or JSON.
- `geni:check` command performing structural JSON drift detection for CI.
- `geni:cache` and `geni:clear` commands with cache integration on routes and commands.
- `schema_overrides` configuration escape hatch for resolving missing migration columns.
- Middleware-derived security requirements (`auth`, `auth:*`) with automatic 401 responses, overridable by `@unauthenticated`.
- Multi-document configuration (`config('geni.apis')`) with zero-config backward compatibility for single-API projects.
- Second renderer: `ElementsRenderer` (`@stoplight/elements`) with publishable view.
- GitHub Actions CI workflow with automated tests and `geni:check` drift detection.

### Documented Divergences from Scramble
1. **Validation rules parsed, not evaluated**: `Rule::in($variable)` with non-literal variables records a diagnostic and falls back to base type rather than evaluating runtime values. (Workaround: inline values or add `@var`).
2. **Column types from migrations, not live database**: Columns created dynamically in loops or conditionals require `schema_overrides`.
3. **`exists` rule resolved via migrations**: Referenced column types are resolved from migration history rather than live database introspection.
