# Geni Documentation

Comprehensive guide to `masitings/geni-api` — the static-analysis OpenAPI 3.1.0 documentation generator for Laravel.

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Installation & Setup](#2-installation--setup)
3. [Configuration Reference (`config/geni.php`)](#3-configuration-reference-configgeniphp)
4. [Documentation Gating & Authentication (`docs_auth`)](#4-documentation-gating--authentication-docs_auth)
5. [Sidebar Promo Card (`promo`)](#5-sidebar-promo-card-promo)
6. [Automatic Static Inference](#6-automatic-static-inference)
7. [PHP Attributes (`Geni\Laravel\Attributes\*`)](#7-php-attributes-genilaravelattributes)
8. [Supported PHPDoc Annotations](#8-supported-phpdoc-annotations)
9. [Multi-Document & API Versioning Support](#9-multi-document--api-versioning-support)
10. [Custom Docs Renderers](#10-custom-docs-renderers)
11. [Artisan Commands](#11-artisan-commands)
12. [End-to-End Example](#12-end-to-end-example)

---

## 1. Architecture Overview

Geni is structured into three distinct namespaces governed by a strict, one-way dependency rule enforced by CI architecture tests:

```
Geni\Laravel  ───▶  Geni\Inference  ───▶  Geni\SchemaReader
```

| Namespace | Responsibility | Framework Dependency |
|---|---|---|
| `Geni\SchemaReader` | Reconstructs the database schema by statically parsing migration files using `nikic/php-parser`. | **No** (`Illuminate` forbidden) |
| `Geni\Inference` | Analyzes controllers, Form Requests, Resources, models, enums, and docblocks to derive OpenAPI schemas. | **No** (`Illuminate` forbidden) |
| `Geni\Laravel` | Service provider, Artisan console commands, route registration, middleware, and documentation renderers. | **Yes** (`Illuminate` permitted) |

Because `SchemaReader` and `Inference` are strictly framework-free, documentation generation does not boot your application or connect to a database.

---

## 2. Installation & Setup

Install Geni as a Composer dependency:

```bash
composer require masitings/geni-api
```

Publish the package configuration:

```bash
php artisan vendor:publish --tag=geni-config
```

Optionally publish the Blade views if you wish to customize markup:

```bash
php artisan vendor:publish --tag=geni-views
```

---

## 3. Configuration Reference (`config/geni.php`)

All settings are configured in `config/geni.php`:

### General Document Information (`info`)

```php
'title' => null, // Defaults to config('app.name')
'version' => '1.0.0',
'description' => 'API documentation overview.',
'terms_of_service' => 'https://example.com/terms',
'contact' => [
    'name' => 'API Support',
    'email' => 'support@example.com',
    'url' => 'https://example.com/support',
],
'license' => [
    'name' => 'MIT',
    'url' => 'https://opensource.org/licenses/MIT',
],
```

### Route & Migration Discovery

- `'api_path' => 'api'`: URL prefix used to discover API endpoints. All routes starting with this prefix are indexed.
- `'api_domain' => null`: Optional domain filter if your API runs on a subdomain (e.g. `api.example.com`).
- `'routes' => null`: Optional `Closure` returning an iterable of `Illuminate\Routing\Route` objects to override prefix-based discovery.
- `'migration_paths' => [database_path('migrations')]`: Directories containing migrations for offline database schema reconstruction.

### Renderer Settings

- `'renderer' => 'blade'`: Specifies the docs UI renderer. Built-in option is `'blade'`. You may also specify any FQCN implementing `Geni\Laravel\Renderer`.
- `'renderers' => ['blade' => [...]]`:
  - `'dark_mode' => true`: Enables Dark/Light mode support.
  - `'tailwind_cdn_url' => 'https://cdn.tailwindcss.com'`: CDN URL for Tailwind CSS.
  - `'alpine_cdn_url' => 'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js'`: CDN URL for Alpine.js.
  - `'custom_css' => null`: Custom CSS string injected into the documentation page.

### Caching

- `'cache.enabled' => false`: Set to `true` in production to cache the generated specification.
- `'cache.key' => 'geni.openapi'`: Cache key prefix.
- `'cache.store' => null`: Laravel cache store (`null` uses default).

### Route Access & Paths

- `'docs_json_path' => 'docs/api.json'`: URI where the raw OpenAPI 3.1.0 specification is served.
- `'docs_ui_path' => 'docs/api'`: URI where the interactive documentation portal is rendered.
- `'middleware' => []`: Custom middleware applied to documentation routes.
- `'restrict_to_local' => true`: Docs routes 404 outside the `local` environment via `RestrictToLocalEnv`. Set to `false` to allow docs in other environments (e.g. staging) — pair this with `docs_auth` so the routes stay access-controlled.

### Schema Overrides

- `'schema_overrides' => []`: Escape hatch mapping `'table.column' => 'type'` (e.g. `'users.legacy_status' => 'string'`) when migrations lack column details or when `schema:dump` was used.

### Model Context Protocol (MCP)

- `'mcp.enabled' => true`: Enables native MCP tool manifest generation and live execution.
- `'mcp.route_path' => 'docs/api/mcp'`: HTTP route returning standard MCP `tools/list` JSON envelope on `GET`, and executing JSON-RPC 2.0 `tools/call` on `POST`.
- `'mcp.tool_naming' => 'snake'`: Tool naming format (`'snake'` or `'operation_id'`).
- `'mcp.include_tags' => []`: Whitelist tags to expose as MCP tools (empty exposes all).
- `'mcp.exclude_tags' => []`: Blacklist tags to exclude from MCP tools.
- `'mcp.exclude_methods' => ['HEAD', 'OPTIONS']`: HTTP methods to omit from tool conversion.
- `'mcp.nest_body' => false`: Merge request body parameters into root object (`false`) vs nest under `'body'` (`true`).
- `'mcp.execution.base_url' => env('GENI_MCP_BASE_URL')`: Base URL for real HTTP dispatch in `tools/call` (falls back to `app.url` or `url('/')`).
- `'mcp.execution.timeout' => 15`: Timeout in seconds for tool execution requests.
- `'mcp.execution.verify_ssl' => true`: SSL certificate verification for tool execution requests. Only disable for local development against self-signed certs — never in production.
- `'mcp.execution.auth.default_bearer_token' => env('GENI_MCP_BEARER_TOKEN')`: Default Bearer token attached to tool execution requests.
- `'mcp.execution.auth.default_api_key' => env('GENI_MCP_API_KEY')`: Default API key attached to tool execution requests.
- `'mcp.execution.auth.api_key_header' => 'X-API-Key'`: Header name for API key authentication.
- `'mcp.execution.auth.schemes' => []`: Scheme-specific credentials mapping scheme names to credentials.
- `'mcp.execution.allow_caller_headers' => false`: Whether AI callers can pass arbitrary `_headers` in tool arguments.

---

## 4. Documentation Gating & Authentication (`docs_auth`)

Geni provides isolated gate authentication for documentation routes (`/docs/api` and `/docs/api.json`), independent of your application's user database and auth guards.

```php
'docs_auth' => [
    'mode' => env('GENI_DOCS_AUTH_MODE', 'basic'), // 'basic' or 'form'
    'username' => env('GENI_DOCS_USERNAME'),
    'password' => env('GENI_DOCS_PASSWORD'),
],
```

### Modes

1. **`'basic'` (Default)**: Prompts the browser's native HTTP Basic Auth dialog. Backward compatible with existing setups.
2. **`'form'`**: Serves a standalone HTML login page (`resources/views/docs-login.blade.php`) matching the docs design system.
   - Unauthenticated UI visits redirect to `/docs/api/login`.
   - Unauthenticated requests to `/docs/api.json` return a `401 Unauthorized` JSON response (`{"message": "Unauthorized."}`).
   - Login uses `hash_equals()` to prevent timing attacks and sets a dedicated session key (`geni_docs_authenticated`).
   - Login attempts are rate-limited to 5 per minute per IP.
   - A "Sign Out" button appears in the documentation sidebar header to clear the session.

To disable authentication completely, leave `username` or `password` set to `null`.

---

## 5. Sidebar Promo Card (`promo`)

An optional promotional banner rendered in the docs sidebar footer. Disabled by default — the package stays content-neutral until a host app opts in.

```php
'promo' => [
    'enabled' => false,
    'title' => null,
    'subtitle' => null,
    'button_text' => 'Learn more',
    'button_url' => null,
    'icon' => null,       // light-mode icon URL
    'icon_dark' => null,  // dark-mode icon URL (preferred; used as-is since the card background is fixed dark)
    'bg_color' => '#022c22',
],
```

- `'button_url'` is validated to `http://`/`https://` only; other schemes (e.g. `javascript:`) are silently dropped.
- When `'button_url'` is set, the whole card becomes a clickable link (`target="_blank"`).

---

## 6. Automatic Static Inference

Geni automatically infers documentation without requiring any annotations:

### 1. Path Parameters
- Inferred from route templates (e.g. `/api/posts/{post}`).
- For route-model binding (e.g. `Post $post`), Geni maps the model to its database table and looks up the primary key column (`id` or custom route key) in your migrations to resolve whether it is an `integer`, `uuid`, or `ulid`.

### 2. Request Bodies
Extracted statically from:
- Form Request classes: inspects the literal array returned by `rules()`.
- Inline validation: `$request->validate([...])` or `$this->validate([...])`.
- Validator calls: `Validator::make($data, [...])` or `validator($data, [...])`.

Mapped validation rules:
- `required`, `nullable`, `string`, `int` / `integer`, `numeric`, `bool` / `boolean`, `array`.
- `email`, `uuid`, `ulid`, `date`, `date_format`.
- `min:x`, `max:x`, `size:x`, `between:x,y`.
- `in:a,b,c` or `Rule::in(['a', 'b'])` → JSON Schema `enum`.
- `Rule::enum(Status::class)` → statically parsed from backed enum cases.
- `exists:table,column` → resolved from the migration schema.
- `confirmed` → automatically adds a `{field}_confirmation` property.
- `file` / `image` → automatically promotes the request media type to `multipart/form-data`.

### 3. Responses
- **Eloquent Models**: Maps public database columns to schema properties.
- **JSON Resources**: Resolves `$this->attribute` and `$this->resource->attribute` accesses in `toArray()` against model columns.
- **Resource Collections**: Handles `PostResource::collection(...)` and custom `ResourceCollection` classes.
- **Pagination**: Wraps collection items in standard `LengthAwarePaginator`, `Paginator`, or `CursorPaginator` envelopes (`data`, `links`, `meta`).
- **Backed Enums**: Mapped as enum types.
- **Plain PHP DTOs**: Extracts public properties and types.
- **Literal JSON**: Extracts structure from `response()->json([...])`.

### 4. Automatic Error Responses
- `422 Unprocessable Content`: Added when validation rules are detected.
- `403 Forbidden`: Added when `$this->authorize()` or `Gate::` calls are found.
- `404 Not Found`: Added when route-model binding is present.
- `abort($code)` / `abort_if($cond, $code)`: Documented with the literal status code.
- `@throws Exception`: Mapped via exception status mappings (e.g. `ValidationException` → 422).

---

## 7. PHP Attributes (`Geni\Laravel\Attributes\*`)

Use PHP 8 attributes to customize and override inferred metadata.

### Group 1: Markers & Exclusions

```php
use Geni\Laravel\Attributes\ExcludeRouteFromDocs;
use Geni\Laravel\Attributes\ExcludeAllRoutesFromDocs;
use Geni\Laravel\Attributes\Hidden;
use Geni\Laravel\Attributes\IgnoreParam;
use Geni\Laravel\Attributes\IgnoreResponse;

// Exclude entire controller
#[ExcludeAllRoutesFromDocs]
class InternalController extends Controller {}

// Exclude specific route action
#[ExcludeRouteFromDocs]
public function internalMethod() {}

// Exclude a parameter from request docs
#[IgnoreParam(name: 'internal_token')]
public function store() {}

// Exclude an error response
#[IgnoreResponse(status: 404)]
public function show() {}
```

### Group 2: Parameters

Parameters can be placed in `query`, `header`, `path`, `body`, or `cookie`.

```php
use Geni\Laravel\Attributes\QueryParameter;
use Geni\Laravel\Attributes\HeaderParameter;
use Geni\Laravel\Attributes\PathParameter;
use Geni\Laravel\Attributes\BodyParameter;
use Geni\Laravel\Attributes\CookieParameter;

#[QueryParameter(
    name: 'filter',
    description: 'Filter search query',
    required: false,
    type: 'string',
    infer: false, // Discard static inference and use explicit definition
    default: 'active'
)]
#[HeaderParameter(name: 'X-Tenant-ID', required: true, type: 'string')]
public function index() {}
```

In Form Requests, annotate properties or `rules()`:

```php
class StoreUserRequest extends FormRequest
{
    #[BodyParameter(name: 'email', description: 'User corporate email address', example: 'alex@acme.com')]
    #[BodyParameter(name: 'role', description: 'Assigned permission role', example: 'editor')]
    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'role' => 'required|string',
        ];
    }
}
```

### Group 3: Metadata & Operation Details

```php
use Geni\Laravel\Attributes\Endpoint;
use Geni\Laravel\Attributes\Group;
use Geni\Laravel\Attributes\Response;
use Geni\Laravel\Attributes\SchemaName;
use Geni\Laravel\Attributes\Api;

#[Group(name: 'Billing', description: 'Invoices and payments', weight: 10)]
#[SchemaName('CustomUserSchema')] // Disambiguate schema names in components.schemas
class InvoiceController extends Controller
{
    #[Endpoint(
        operationId: 'createInvoice',
        title: 'Issue Invoice',
        description: 'Creates a draft invoice for review.'
    )]
    #[Response(status: 201, description: 'Invoice created successfully')]
    #[Api(only: ['public', 'v1'])] // Restrict to specific named document
    public function store() {}
}
```

---

## 8. Supported PHPDoc Annotations

Geni provides 100% drop-in compatibility with Scramble PHPDoc tags:

| Tag | Target | Description |
|---|---|---|
| `@response [status] Type [desc]` | Method | Explicit response type override. |
| `@status` + `@body` | Return | Explicit status and payload type. |
| `@description` | Method / Return | Extended markdown description. |
| `@var` | Field / Property | Redefine inferred type using PHPStan syntax. |
| `@example` | Field / Property | Provide a sample value. |
| `@format` | Field / Property | Sets JSON Schema format (e.g. `uuid`, `email`). |
| `@default` | Field / Property | Sets default value. |
| `@query` | Field | Moves a parameter to query string on non-GET methods. |
| `@ignoreParam` | Method / Field | Excludes parameter from documentation. |
| `@hidden` | Property | Hides property from request/response schema. |
| `@deprecated` | Class / Method | Marks endpoint or schema deprecated. |
| `@notDeprecated` | Method | Un-deprecates an action inside a deprecated class. |
| `@operationId` | Method | Overrides generated operationId. |
| `@requestMediaType` | Method | Overrides content-type (e.g. `application/x-www-form-urlencoded`). |
| `@tags Tag1, Tag2` | Class / Method | Assigns tags. |
| `@throws Exception` | Method | Documents error responses based on thrown exceptions. |
| `@unauthenticated` | Method | Excludes endpoint from security derivation. |
| `@mixin Model` | Resource | Explicitly specifies backing model for resource inference. |

---

## 9. Multi-Document & API Versioning Support

Geni supports hosting multiple independent API versions or domain-specific specifications (e.g. `v1`, `v2`, `internal`):

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

### Route Generation & Version Switcher Dropdown
- **Automatic Dedicated Routes**: When `apis` is configured, Geni registers dedicated routes per API version:
  - UI Portal: `/docs/api/v1`, `/docs/api/v2`
  - JSON Spec: `/docs/api/v1.json`, `/docs/api/v2.json`
  - MCP Discovery: `/docs/api/v1/mcp`, `/docs/api/v2/mcp`
- **Interactive Version Switcher**: When more than one API is configured, an interactive dropdown selector automatically appears in the documentation portal sidebar header. Selecting a version switches the OpenAPI specification, reloads the endpoint tree, updates the title and version badge, and synchronizes browser history via `history.pushState()`.
- **Attribute Scoping**: Endpoints decorated with `#[Api(only: ['v2'])]` appear exclusively in their designated version specification.
- **Backward Compatibility**: If `'apis' => []` is left empty, Geni operates in single-document mode with zero dropdown UI and zero additional routes.

---

## 10. Custom Docs Renderers

You can implement custom documentation viewports by implementing `Geni\Laravel\Renderer`:

```php
namespace App\Docs;

use Geni\Inference\Document\OpenApiDocument;
use Geni\Laravel\Renderer;
use Illuminate\Http\Response;

class CustomRenderer implements Renderer
{
    public function render(OpenApiDocument $document, array $config): Response
    {
        $jsonUrl = url(config('geni.docs_json_path', 'docs/api.json'));

        return response()->view('custom-docs', [
            'doc' => $document,
            'specUrl' => $jsonUrl,
        ]);
    }
}
```

Register your custom renderer in `config/geni.php`:

```php
'renderer' => \App\Docs\CustomRenderer::class,
```

---

## 11. Artisan Commands

### `geni:export`
Generates and writes the OpenAPI specification to a file:

```bash
php artisan geni:export --path=openapi.json --api=default
```

### `geni:mcp`
Generates a Model Context Protocol (MCP) tool manifest or client configuration for AI agents (Claude Code, Cursor, Claude Desktop):

```bash
# Export MCP tool manifest to file
php artisan geni:mcp --path=mcp-manifest.json

# Print tool manifest JSON to stdout
php artisan geni:mcp --path=-

# Output client configuration snippet for claude_desktop_config.json
php artisan geni:mcp --format=client-config --base-url=https://api.example.com
```

### `geni:mcp:serve`
Runs the native Model Context Protocol (MCP) server over standard input/output (stdio), listening for JSON-RPC 2.0 requests (`initialize`, `ping`, `tools/list`, `tools/call`). Injects configured auth tokens automatically and executes real HTTP requests:

```bash
php artisan geni:mcp:serve --api=default --timeout=15
```

#### Claude Desktop Integration (`claude_desktop_config.json`)
```json
{
  "mcpServers": {
    "my-laravel-api": {
      "command": "php",
      "args": ["artisan", "geni:mcp:serve"],
      "cwd": "/path/to/laravel-app",
      "env": {
        "GENI_MCP_BASE_URL": "http://localhost:8000",
        "GENI_MCP_BEARER_TOKEN": "your-sanctum-token",
        "GENI_MCP_API_KEY": "your-api-key"
      }
    }
  }
}
```

#### Claude Code Integration (`.mcp.json`)
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

### `geni:check`
CI drift detection. Regenerates the document in memory and performs a structural JSON comparison against the committed file:

```bash
php artisan geni:check --path=openapi.json --api=default
```
- Exits `0` with no output when clean.
- Exits `1` with a formatted diff if discrepancies are detected.

### `geni:analyze`
Lists all diagnostics where inference fell back to base types:

```bash
# Formatted CLI table
php artisan geni:analyze

# Machine-readable JSON for CI integration
php artisan geni:analyze --json
```

### `geni:cache` & `geni:clear`
Manage the generated document cache:

```bash
php artisan geni:cache --api=default
php artisan geni:clear --api=default
```

---

## 12. End-to-End Example

### Controller (`app/Http/Controllers/Api/ProjectController.php`)

```php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Geni\Laravel\Attributes\Endpoint;
use Illuminate\Http\Response;

/**
 * @tags Projects
 */
class ProjectController extends Controller
{
    #[Endpoint(title: 'List Projects', description: 'Retrieve a paginated list of projects.')]
    public function index()
    {
        return ProjectResource::collection(Project::paginate(15));
    }

    #[Endpoint(title: 'Create Project', description: 'Create a new project.')]
    public function store(StoreProjectRequest $request): ProjectResource
    {
        $project = Project::create($request->validated());

        return new ProjectResource($project);
    }

    #[Endpoint(title: 'Get Project', description: 'Retrieve project details by ID.')]
    public function show(Project $project): ProjectResource
    {
        return new ProjectResource($project);
    }

    #[Endpoint(title: 'Delete Project', description: 'Permanently remove a project.')]
    public function destroy(Project $project): Response
    {
        $project->delete();

        return response()->noContent();
    }
}
```

### Form Request (`app/Http/Requests/Api/StoreProjectRequest.php`)

```php
namespace App\Http\Requests\Api;

use Geni\Laravel\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    #[BodyParameter(name: 'name', description: 'Project title', example: 'Website Redesign')]
    #[BodyParameter(name: 'status', description: 'Initial status', example: 'active')]
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|in:active,archived',
        ];
    }
}
```

### Resource (`app/Http/Resources/ProjectResource.php`)

```php
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Project
 */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}
```

Geni processes the routes above, resolves column types from `database/migrations/*_create_projects_table.php`, and compiles a fully typed OpenAPI 3.1.0 specification ready to view at `/docs/api` or export via `php artisan geni:export`.
