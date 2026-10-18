# Geni

[![Latest Version](https://img.shields.io/github/v/release/masitings/geni-api?label=latest)](https://github.com/masitings/geni-api/releases)
[![Tests](https://img.shields.io/github/actions/workflow/status/masitings/geni-api/tests.yml?branch=main&label=tests)](https://github.com/masitings/geni-api/actions/workflows/tests.yml)
[![License](https://img.shields.io/github/license/masitings/geni-api)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%5E8.2-8892BF)](https://php.net)
[![Laravel](https://img.shields.io/badge/laravel-10.x--13.x-FF2D20)](https://laravel.com)

Geni is a static-analysis OpenAPI 3.1.0 documentation generator for Laravel. It reads your source files (routes, controllers, Form Requests, Resources, migrations) and produces a specification without ever booting your application or connecting to a database.

It includes a built-in standalone interactive documentation portal (`BladeRenderer`) that runs out-of-the-box with **zero JavaScript build step** (no React, no Vite, no node_modules required).

Geni supports standard OpenAPI docblock conventions and PHP 8 attributes. If you are migrating from existing documentation tools like Scramble, Geni understands familiar PHPDoc tags and attribute conventions, making switching straightforward.

> 📖 **Full Documentation**: See [geni.masiting.dev](https://geni.masiting.dev/) for complete architecture details, configuration reference, PHP attributes, and end-to-end examples.

![Geni documentation portal](.github/assets/screenshot.jpg)

## Key Highlights

- **Zero database connections.** Column types are reconstructed offline from migration files.
- **Zero application boot for inference.** Route discovery is the only runtime touchpoint; everything else is static AST analysis.
- **Standalone docs UI out of the box.** Powered by Blade, Tailwind Play CDN, and Alpine.js with live search, Prism.js syntax highlighting, Markdown descriptions and an interactive Try It console. Prefer a hosted UI? Switch to Scalar or Stoplight Elements with one config value.
- **Access control.** Docs are open in `local`; elsewhere a `viewApiDocs` gate decides (the Scramble convention), or protect them with isolated form-based / HTTP Basic credentials independent of application user tables.
- **Runs anywhere your code does** (local checkouts, CI pipelines, pre-commit hooks).

## Installation

```bash
composer require masitings/geni-api
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=geni-config
```

## Quick Start

1. Configure migration paths and API routes in `config/geni.php` (defaults to `database_path('migrations')` and `/api/*`).
2. Export a specification file:

   ```bash
   php artisan geni:export --path=openapi.json
   ```

3. Or browse the interactive documentation live in your browser at `/docs/api` (open in the `local` environment; see [Access & UI](#access--ui) for other environments).

No annotations required: for endpoints using route-model binding, validated request bodies, and `JsonResource` responses, Geni infers schemas automatically.

## Commands Overview

| Command | Description |
|---|---|
| `php artisan geni:export {--path=} {--api=}` | Export the OpenAPI specification to a file |
| `php artisan geni:mcp {--path=} {--format=} {--base-url=}` | Generate an MCP tool manifest or client configuration for AI agents |
| `php artisan geni:mcp:serve {--api=} {--base-url=} {--timeout=}` | Run live MCP stdio server responding to `tools/list` and executing `tools/call` |
| `php artisan geni:check {--path=} {--api=}` | Compare current routes against committed spec; exits non-zero on drift |
| `php artisan geni:analyze {--json} {--api=}` | Inspect all unresolved inference diagnostics |
| `php artisan geni:cache {--api=}` / `geni:clear` | Pre-generate and cache the document, or clear cache |

## Model Context Protocol (MCP) Integration

Geni natively exports and executes your Laravel API as Model Context Protocol (MCP) tools, turning your application into an AI-ready toolkit. AI agents (Claude Code, Cursor, Windsurf, Claude Desktop) can discover, understand, and execute your endpoints directly without manual wrapper code or extra setup.

### What AI Agents Can Do With Your API

- **Autonomous Endpoint Discovery (`tools/list`)**: AI assistants automatically read all your routes, complete with request body parameters, validation constraints (required, email, min, max, enum), and response models inferred from your source code.
- **Direct Action Execution (`tools/call`)**: An AI agent can perform real tasks via your API. For example, ask your agent: *"Find the user with email alex@example.com and set their role to editor"*, and the agent will call `GET /api/users` and `PATCH /api/users/{id}` autonomously!
- **Safe Credential Injection**: Configure your Bearer token or API key in your environment. Credentials are automatically attached during execution and never exposed to the LLM context or tool schemas.

### 1. Live Stdio Execution (`php artisan geni:mcp:serve`)

Configure Claude Desktop or Claude Code to run Geni's built-in MCP server:

**Claude Desktop (`claude_desktop_config.json`)**:
```json
{
  "mcpServers": {
    "my-laravel-api": {
      "command": "php",
      "args": ["artisan", "geni:mcp:serve"],
      "cwd": "/path/to/laravel-app",
      "env": {
        "GENI_MCP_BEARER_TOKEN": "your-api-bearer-token",
        "GENI_MCP_API_KEY": "your-api-key"
      }
    }
  }
}
```

**Claude Code (`.mcp.json`)**:
```json
{
  "mcpServers": {
    "api": {
      "command": "php",
      "args": ["artisan", "geni:mcp:serve"]
    }
  }
}
```

### 2. Declarative Tools Manifest & Remote HTTP Discovery

- **Export Manifest File**:
  ```bash
  php artisan geni:mcp --path=mcp-manifest.json
  ```
- **Generate Client Bridge Configuration**:
  ```bash
  php artisan geni:mcp --format=client-config --base-url=https://api.example.com
  ```
- **Live HTTP Discovery & Execution Endpoint**:
  - `GET /docs/api/mcp` returns the standard MCP `tools/list` JSON envelope.
  - `POST /docs/api/mcp` accepts JSON-RPC 2.0 requests sent as `application/json` (`initialize`, `tools/list`, `tools/call`).
  - `tools/call` over HTTP is off by default because it runs endpoints with the server's configured credentials for anyone who can open the docs. Enable it with `GENI_MCP_HTTP_EXECUTION=true` (`geni.mcp.http_execution`). `geni:mcp:serve` is not affected.

## Access & UI

Outside the `local` environment the docs return 404 unless you define a `viewApiDocs` gate, exactly like Scramble:

```php
// AppServiceProvider::boot()
Gate::define('viewApiDocs', fn (?User $user) => in_array($user?->email, ['admin@example.com'], true));
```

User-based gates need a session: add `'web'` to `geni.middleware`. A denying gate returns 403. Set `restrict_to_local` to `false` to rely only on `docs_auth`.

Pick the UI with `geni.renderer` (or `renderer` inside an entry of `geni.apis`):

| Renderer | UI |
|---|---|
| `blade` (default) | Built-in portal: version switcher, Try It console, MCP-friendly, no build step |
| `scalar` | [Scalar API Reference](https://github.com/scalar/scalar); options in `renderers.scalar.configuration` are passed to Scalar as-is |
| `elements` | [Stoplight Elements](https://github.com/stoplightio/elements), Scramble's default UI |

Scalar and Elements load pinned CDN builds; override `script_url` / `stylesheet_url` to self-host them. Descriptions written in PHPDoc support a safe Markdown subset in the built-in portal (paragraphs, lists, headings, fenced code, `code`, **bold**, *italic*, links).

## Customizing the Document

Register transformers in a service provider's `boot()` method. Registrations for `default` also apply to every API in `geni.apis`; pass a name to target one API.

```php
use Geni\Inference\Document\OpenApiDocument;
use Geni\Inference\Document\Operation;
use Geni\Laravel\DiscoveredRoute;
use Geni\Laravel\Geni;

Geni::configure()
    ->withDocumentTransformers(function (OpenApiDocument $document, string $apiName) {
        $document->info->description = 'Public API for Acme.';
    })
    ->withOperationTransformers(function (Operation $operation, DiscoveredRoute $route) {
        if (in_array('throttle:api', $route->middleware, true)) {
            $operation->responses['429'] = ['description' => 'Too many requests'];
        }
    })
    ->withRuleTransformers(Ipv4CidrRule::class)          // Geni\Inference\RuleTransformer
    ->withExceptionResponses(PaymentRequiredMapper::class); // Geni\Inference\ExceptionToResponse

Geni::configure('v2')->afterOpenApiGenerated(fn (OpenApiDocument $document) => $document->info->title .= ' (beta)');
```

Classes implementing `Geni\Laravel\Contracts\DocumentTransformer`, `Geni\Laravel\Contracts\OperationTransformer`, `Geni\Inference\RuleTransformer` or `Geni\Inference\ExceptionToResponse` can also be listed in `config('geni.extensions')` (or `geni.apis.{name}.extensions`).

`servers` are configured as description => URL:

```php
'servers' => ['Production' => 'https://api.example.com', 'Local' => '/'],
```

### Operation IDs

Every operation gets an `operationId`, chosen the way Scramble does: `#[Endpoint(operationId: ...)]` or `@operationId`, then the route name without a leading `api.` (`api.users.index` → `users.index`), then the controller and method (`UserController@show` → `user.show`), made unique across the document. A `PATCH` documented next to `PUT` on the same route gets a `_patch` suffix. Generated ids do not rename MCP tools; set `geni.mcp.tool_naming` to `operation_id` to use them.

## Multi-Version API Documentation

Document multiple versions (e.g. `v1`, `v2`) or separate domains independently with automatic version-switching in the UI:

```php
// config/geni.php
'apis' => [
    'v1' => [
        'title' => 'API v1 (Legacy)',
        'version' => '1.4.0',
        'api_path' => 'api/v1',
    ],
    'v2' => [
        'title' => 'API v2 (Latest)',
        'version' => '2.0.0',
        'api_path' => 'api/v2',
    ],
],
```

When multiple versions are defined:
- Dedicated routes are registered: `/docs/api/v1`, `/docs/api/v2`, `/docs/api/v1.json`, `/docs/api/v2.json`, `/docs/api/v1/mcp`, `/docs/api/v2/mcp`.
- An interactive version switcher dropdown automatically appears in the sidebar header of the documentation portal.
- Single-API setups (empty `apis => []`) retain standard zero-overhead behavior without dropdowns.

## Spatie Laravel Data Support

Geni provides first-class, opt-in support for [Spatie Laravel Data](https://spatie.be/docs/laravel-data) (`spatie/laravel-data`):
- **Request parameters**: Controller action parameters type-hinting a class extending `Spatie\LaravelData\Data` automatically generate the OpenAPI request body schema.
- **Responses**: Controller return statements returning `new DataClass(...)`, `DataClass::from(...)`, `DataClass::collect(...)`, or declaring a `DataClass` return type hint generate accurate OpenAPI response schemas.
- **Validation attributes**: Spatie validation attributes (`#[Required]`, `#[Min]`, `#[Max]`, `#[Email]`, `#[Url]`, `#[Regex]`) are statically mapped to JSON Schema constraints.
- **Nested Data & Collections**: Nested Data classes and `DataCollection` returns are recursively resolved and referenced.
- **Zero runtime overhead**: Fully static AST analysis. The package never requires `spatie/laravel-data` at runtime.

## Laravel Actions Support

Geni provides first-class, opt-in support for [Laravel Actions](https://laravelactions.com) (`lorisleiva/laravel-actions`):
- **Single-action routes**: Routes registered as `Route::get('/users', CreateUserAction::class)` are automatically detected. Geni resolves `asController()` (preferred) or `handle()` as the execution method.
- **Rules extraction**: When an action defines a `rules()` method (or uses `ActionRequest`), request validation rules are statically inferred into the OpenAPI request body schema.
- **Authorization**: When an action defines an `authorize()` method with non-trivial logic, a 403 Forbidden response is automatically documented.
- **Summary derivation**: Operation summaries are cleanly derived from action class names (e.g. `CreateUserAction` -> "Create user") when no explicit summary annotation is provided.

## Spatie Laravel Query Builder Support

Geni statically inspects [Spatie Laravel Query Builder](https://spatie.be/docs/laravel-query-builder) (`spatie/laravel-query-builder`) calls on GET operations:
- **`allowedFilters(...)`**: Generates `filter[field]` query parameters with database column types automatically resolved from your migrations. Supports `AllowedFilter::exact`, `partial`, `scope`, and `trashed`.
- **`allowedSorts(...)` and `defaultSort(...)`**: Generates a unified `sort` query parameter with expanded ascending and descending enums and default values.
- **`allowedIncludes(...)`**: Generates an `include` query parameter listing available relation names.
- **`allowedFields(...)`**: Generates `fields[resource]` query parameters for sparse fieldsets.
- **`allowedAppends(...)`**: Generates an `append` query parameter listing dynamic model accessors.

## Design Decisions & Static Analysis Nuances

Geni parses code statically rather than evaluating runtime values:

1. **`Rule::in($variable)`**: Non-literal variables cannot be expanded into an enum. Geni documents the base type and records a diagnostic. *(Workaround: inline literal values, or add `@var`)*.
2. **Column types from migrations**: Relies on migration history. Pruned migrations via `schema:dump` require `schema_overrides`.
3. **`exists:table,column`**: Resolved offline from migration schemas rather than live database queries.

---

For in-depth guides on all features, custom renderers, security schemes, and PHP attributes, please read the full documentation at [geni.masiting.dev](https://geni.masiting.dev/).

## Disclaimer

Scramble is a trademark of dedoc.co and Roman Lytvynenko. Geni is an independent open-source project and is not affiliated with, endorsed by, or sponsored by dedoc.co.

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.

---

[![Support childhood cancer research](.github/assets/banner.jpg)](https://pcrf1.app.neoncrm.com/campaigns/2026-pcrf---childhood-cancer-awareness)
