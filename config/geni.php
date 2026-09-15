<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Document Info (OpenAPI `info` object)
    |--------------------------------------------------------------------------
    |
    | Populates the "Overview" section of the docs UI. All of these are
    | optional except `title`, which falls back to config('app.name').
    |
    */

    'title' => null, // defaults to config('app.name')
    'version' => '1.0.0',
    'description' => null,
    'terms_of_service' => null,

    'contact' => [
        'name' => null,
        'email' => null,
        'url' => null,
    ],

    'license' => [
        'name' => null, // e.g. 'MIT' -- required for the license block to appear at all
        'url' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | API Path
    |--------------------------------------------------------------------------
    |
    | Routes whose URI begins with this prefix are discovered for documentation.
    | Ignored when `routes` resolver closure is set.
    |
    */

    'api_path' => 'api',

    /*
    |--------------------------------------------------------------------------
    | API Domain
    |--------------------------------------------------------------------------
    |
    | The domain under which API routes are served, used for server/url resolution.
    |
    */

    'api_domain' => null,

    /*
    |--------------------------------------------------------------------------
    | Route Resolver Closure
    |--------------------------------------------------------------------------
    |
    | When set, this closure is called instead of prefix-based discovery. It
    | receives no arguments and must return an array of Illuminate\Route
    | objects (or an iterable). This lives in Geni\Laravel, the only
    | namespace permitted to touch the live route collection.
    |
    */

    'routes' => null,

    /*
    |--------------------------------------------------------------------------
    | Migration Paths
    |--------------------------------------------------------------------------
    |
    | Paths scanned by SchemaReader to reconstruct the database schema.
    | Used for path-parameter and resource field typing.
    |
    */

    'migration_paths' => [database_path('migrations')],

    /*
    |--------------------------------------------------------------------------
    | Renderer
    |--------------------------------------------------------------------------
    |
    | The renderer implementation used for the docs UI route.
    | Supported built-in options: 'blade' (default standalone portal),
    | or a custom class name implementing Geni\Laravel\Renderer.
    |
    */

    'renderer' => 'blade',

    'renderers' => [
        'blade' => [
            'dark_mode' => true,
            'tailwind_cdn_url' => 'https://cdn.tailwindcss.com',
            'alpine_cdn_url' => 'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js',
            'custom_css' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Enable schema/inference caching for performance.
    |
    */

    'cache' => [
        'enabled' => false,
        'key' => 'geni.openapi',
        'store' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Docs Route Paths
    |--------------------------------------------------------------------------
    |
    | URIs the JSON document and the docs UI are served at.
    |
    */

    'docs_json_path' => 'docs/api.json',
    'docs_ui_path' => 'docs/api',

    /*
    |--------------------------------------------------------------------------
    | Restrict to Local Environment
    |--------------------------------------------------------------------------
    |
    | Docs routes 404 outside the 'local' environment by default. Set to
    | false to allow docs in other environments (e.g. staging), relying on
    | `docs_auth` for access control instead.
    |
    */

    'restrict_to_local' => true,

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware applied to the docs UI routes.
    |
    */

    'middleware' => [],

    /*
    |--------------------------------------------------------------------------
    | Docs Authentication (Basic or Custom Form)
    |--------------------------------------------------------------------------
    |
    | Protect the docs UI and JSON specification routes independent of the app's
    | own user table or auth guards. Set username and password to enable.
    |
    | Supported modes:
    |   'basic' - Browser-native HTTP Basic Auth prompt (default, backward-compatible).
    |   'form'  - Beautiful custom HTML form login page matching the docs UI theme.
    |             Operates on its own isolated session key without touching App Auth.
    |
    | Leave username or password null to disable docs authentication entirely.
    |
    */

    'docs_auth' => [
        'mode' => env('GENI_DOCS_AUTH_MODE', 'basic'),
        'username' => env('GENI_DOCS_USERNAME'),
        'password' => env('GENI_DOCS_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema Overrides
    |--------------------------------------------------------------------------
    |
    | Manual escape hatch mapping 'table.column' => 'type' (e.g. 'posts.legacy_field' => 'string')
    | when the migration reader cannot resolve a column type.
    |
    */

    'schema_overrides' => [],

    /*
    |--------------------------------------------------------------------------
    | Security & Authentication Derivation
    |--------------------------------------------------------------------------
    |
    | Middleware patterns that mark a route as protected, and the OpenAPI
    | security scheme definition to attach to protected endpoints.
    |
    */

    'middleware_security' => [
        'auth',
        'auth:*',
    ],

    'security_scheme' => [
        'type' => 'http',
        'scheme' => 'bearer',
        'bearerFormat' => 'JWT',
        'description' => 'Bearer token authentication',
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-Middleware Security Schemes
    |--------------------------------------------------------------------------
    |
    | Optional: document different middleware with different security schemes,
    | instead of one global scheme for everything matched by
    | `middleware_security` above. Checked in order, first match wins, before
    | falling back to `security_scheme`. Example:
    |
    |   'middleware_security_schemes' => [
    |       'auth:sanctum' => [
    |           'name' => 'sanctumAuth',
    |           'scheme' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
    |       ],
    |       'auth:api-key' => [
    |           'name' => 'apiKeyAuth',
    |           'scheme' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
    |       ],
    |   ],
    |
    */

    'middleware_security_schemes' => [],

    'security_strategy' => null,

    /*
    |--------------------------------------------------------------------------
    | Multi-Document & API Versioning Support
    |--------------------------------------------------------------------------
    |
    | Configure multiple named API specifications (e.g. 'v1', 'v2', 'internal').
    | Each key overrides top-level options for that version:
    |   - 'title', 'version', 'description', 'contact', 'license'
    |   - 'api_path': route prefix to discover (e.g. 'api/v1')
    |   - 'docs_ui_path': documentation UI URL (defaults to {docs_ui_path}/{name})
    |   - 'docs_json_path': raw JSON URL (defaults to {docs_ui_path}/{name}.json)
    |   - 'mcp_route_path': MCP discovery endpoint (defaults to {docs_ui_path}/{name}/mcp)
    |   - 'routes': custom resolver closure
    |   - 'migration_paths', 'schema_overrides', 'renderer'
    |
    | When multiple APIs are defined, an interactive version switcher dropdown
    | appears automatically in the documentation portal sidebar.
    |
    | Leave empty ([]) for standard single-document mode (no dropdown, zero overhead).
    |
    */

    'apis' => [
        // 'v1' => [
        //     'title' => 'API v1 (Legacy)',
        //     'version' => '1.4.0',
        //     'api_path' => 'api/v1',
        // ],
        // 'v2' => [
        //     'title' => 'API v2 (Latest)',
        //     'version' => '2.0.0',
        //     'api_path' => 'api/v2',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sidebar Promo Card
    |--------------------------------------------------------------------------
    |
    | Optional promotional card rendered at the bottom of the docs sidebar.
    | Disabled by default, the package stays content-neutral until a host
    | app opts in with its own title/subtitle/button.
    |
    */

    'promo' => [
        'enabled' => false,
        'title' => null,
        'subtitle' => null,
        'button_text' => 'Learn more',
        'button_url' => null,
        // Optional icon images; falls back to a generic bolt icon when both are null.
        // 'icon' shows in light mode, 'icon_dark' shows in dark mode.
        'icon' => null,
        'icon_dark' => null,
        // Card background color. Any valid CSS color. The card stays this
        // dark, fixed look regardless of the docs light/dark theme.
        'bg_color' => '#022c22',
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Context Protocol (MCP) Tool Manifest & Real Execution
    |--------------------------------------------------------------------------
    |
    | Configure automatic MCP tool manifest generation, live discovery endpoint,
    | and real execution (tools/call) via stdio server (`php artisan geni:mcp:serve`)
    | or HTTP JSON-RPC endpoint (`POST docs/api/mcp`).
    |
    */

    'mcp' => [
        'enabled' => true,
        'route_path' => 'docs/api/mcp',
        'tool_naming' => 'snake', // 'snake' | 'operation_id'
        'include_tags' => [],     // Whitelist tags, empty = all
        'exclude_tags' => [],     // Blacklist tags
        'exclude_methods' => ['HEAD', 'OPTIONS'],
        'nest_body' => false,     // Merge body properties to root object vs nesting in 'body'

        /*
        |--------------------------------------------------------------------------
        | Live Execution (tools/call) Configuration
        |--------------------------------------------------------------------------
        |
        | Security note: Authentication tokens/keys configured here are injected
        | directly at HTTP request dispatch time and are NEVER leaked or exposed in
        | tool descriptions, schemas, or manifest exports.
        |
        */
        'execution' => [
            'base_url' => env('GENI_MCP_BASE_URL'), // defaults to app.url or url('/')
            'timeout' => (int) env('GENI_MCP_TIMEOUT', 15),
            'verify_ssl' => env('GENI_MCP_VERIFY_SSL', true),
            'auth' => [
                'default_bearer_token' => env('GENI_MCP_BEARER_TOKEN'),
                'default_api_key' => env('GENI_MCP_API_KEY'),
                'api_key_header' => env('GENI_MCP_API_KEY_HEADER', 'X-API-Key'),
                'schemes' => [
                    // 'sanctumAuth' => ['type' => 'bearer', 'token' => env('GENI_MCP_SANCTUM_TOKEN')],
                    // 'apiKeyAuth' => ['type' => 'header', 'header' => 'X-API-Key', 'value' => env('GENI_MCP_API_KEY')],
                ],
            ],
            'allow_caller_headers' => false,
        ],
    ],

    'extensions' => [],
];
