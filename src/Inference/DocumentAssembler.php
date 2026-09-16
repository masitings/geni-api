<?php

declare(strict_types=1);

namespace Geni\Inference;

use Geni\Inference\Document\Contact;
use Geni\Inference\Document\License;
use Geni\Inference\Document\OpenApiDocument;
use Geni\Inference\Document\Operation;
use Geni\Inference\Document\Parameter;
use Geni\Inference\Document\PathItem;
use Geni\Inference\Document\RequestBody;
use Geni\Inference\Document\Schema;
use Geni\Inference\QueryBuilder\QueryBuilderExtractor;
use Geni\SchemaReader\DatabaseSchema;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * Assembles an OpenApiDocument from plain route arrays, inference engines,
 * the Phase 5 annotation layer, and Phase 6 production features (security, overrides, multi-doc).
 *
 * Framework-free: Does not use Illuminate\*.
 */
final class DocumentAssembler
{
    private ActionAstAcquirer $astAcquirer;

    private PathParameterInferer $pathInferer;

    private ValidationRuleExtractor $ruleExtractor;

    private ValidationRuleSchemaMapper $schemaMapper;

    private ResourceResponseInferer $responseInferer;

    private ErrorResponseInferer $errorInferer;

    private PhpDocAnnotationParser $phpDocParser;

    private AttributeAnnotationReader $attrReader;

    private AnnotationMerger $annotationMerger;

    private ResponseTypeResolver $responseResolver;

    private QueryBuilderExtractor $queryBuilderExtractor;

    /** @var list<array{file: string, line: int, reason: string, operation: string}> */
    public array $recordedDiagnostics = [];

    public function __construct(
        ?ActionAstAcquirer $astAcquirer = null,
        ?PathParameterInferer $pathInferer = null,
        ?ValidationRuleExtractor $ruleExtractor = null,
        ?ValidationRuleSchemaMapper $schemaMapper = null,
        ?ResourceResponseInferer $responseInferer = null,
        ?ErrorResponseInferer $errorInferer = null,
        ?PhpDocAnnotationParser $phpDocParser = null,
        ?AttributeAnnotationReader $attrReader = null,
        ?AnnotationMerger $annotationMerger = null,
        ?ResponseTypeResolver $responseResolver = null,
        ?QueryBuilderExtractor $queryBuilderExtractor = null,
    ) {
        $this->astAcquirer = $astAcquirer ?? new ActionAstAcquirer;
        $this->pathInferer = $pathInferer ?? new PathParameterInferer;
        $this->ruleExtractor = $ruleExtractor ?? new ValidationRuleExtractor;
        $this->schemaMapper = $schemaMapper ?? new ValidationRuleSchemaMapper;
        $this->responseInferer = $responseInferer ?? new ResourceResponseInferer;
        $this->errorInferer = $errorInferer ?? new ErrorResponseInferer;
        $this->phpDocParser = $phpDocParser ?? new PhpDocAnnotationParser;
        $this->attrReader = $attrReader ?? new AttributeAnnotationReader;
        $this->annotationMerger = $annotationMerger ?? new AnnotationMerger;
        $this->responseResolver = $responseResolver ?? new ResponseTypeResolver;
        $this->queryBuilderExtractor = $queryBuilderExtractor ?? new QueryBuilderExtractor;
    }

    /**
     * @param  list<array{uri: string, methods: list<string>, action: array<string, mixed>, middleware?: list<string>, name?: ?string, file?: ?string, line?: ?int}>  $routes
     * @param  array{title?: string, version?: string, description?: string, terms_of_service?: string, contact?: array{name?: ?string, url?: ?string, email?: ?string}, license?: array{name: string, url?: ?string}}  $infoOptions
     * @param  array<string, string>  $schemaOverrides
     * @param  array<string, mixed>  $securityConfig
     */
    public function assemble(
        array $routes,
        array $infoOptions = [],
        ?DatabaseSchema $dbSchema = null,
        array $schemaOverrides = [],
        array $securityConfig = [],
        ?string $apiName = null,
    ): OpenApiDocument {
        $this->recordedDiagnostics = [];
        $document = new OpenApiDocument;

        $document->info->title = $infoOptions['title'] ?? 'Laravel API';
        $document->info->version = $infoOptions['version'] ?? '1.0.0';
        if (isset($infoOptions['description'])) {
            $document->info->description = $infoOptions['description'];
        }
        if (isset($infoOptions['terms_of_service'])) {
            $document->info->termsOfService = $infoOptions['terms_of_service'];
        }
        if (! empty($infoOptions['contact'])) {
            $document->info->contact = new Contact($infoOptions['contact']);
        }
        if (! empty($infoOptions['license']['name'])) {
            $document->info->license = new License($infoOptions['license']);
        }

        $this->responseInferer->setSchemaOverrides($schemaOverrides);

        // Security configuration defaults (FR-006)
        $securityEnabled = $securityConfig['enabled'] ?? true;
        $authPatterns = $securityConfig['middleware'] ?? ['auth', 'auth:*'];
        $schemeName = $securityConfig['scheme_name'] ?? 'bearerAuth';
        $schemeDef = $securityConfig['scheme'] ?? [
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'JWT',
            'description' => 'Bearer token authentication',
        ];

        /**
         * Per-middleware scheme rules, checked in order before the single
         * global scheme above. Each rule: ['middleware' => list<string>, 'name' => string, 'scheme' => array].
         * Lets e.g. `auth:sanctum` document as bearer while a custom API-key
         * middleware documents as apiKey, without one global scheme for everything.
         *
         * @var list<array{middleware: list<string>, name: string, scheme: array<string, mixed>}>
         */
        $schemeRules = $securityConfig['rules'] ?? [];

        // Process routes in order of declaration (FR-013, fsd B7.8)
        foreach ($routes as $routeData) {
            $actionData = $routeData['action'];
            $className = $actionData['class'] ?? null;
            $methodName = $actionData['method'] ?? null;

            // Check class-level attribute exclusions (FR-008 / ExcludeAllRoutesFromDocs)
            $attrClass = $className !== null ? $this->attrReader->readClassAttributes($className) : [];
            if (! empty($attrClass['excludeAll'])) {
                continue;
            }

            // Check method-level attribute exclusions (FR-008 / ExcludeRouteFromDocs)
            $attrMethod = ($className !== null && $methodName !== null)
                ? $this->attrReader->readMethodAttributes($className, $methodName)
                : ['parameters' => [], 'responses' => [], 'ignoredParams' => [], 'ignoredResponses' => []];

            if (! empty($attrMethod['excludeRoute'])) {
                continue;
            }

            // Multi-document filtering via x-api / #[Api(only: [...])] (FR-009 / TASK-011)
            $apiOnly = $attrMethod['apiOnly'] ?? $attrClass['apiOnly'] ?? null;
            if ($apiName !== null && $apiName !== 'default' && $apiOnly !== null) {
                if (! in_array($apiName, $apiOnly, true)) {
                    continue;
                }
            }

            $uri = $routeData['uri'];
            // Normalize URI: ensure leading slash
            $normalizedUri = str_starts_with($uri, '/') ? $uri : '/'.$uri;

            $pathItem = $document->paths->items[$normalizedUri] ?? new PathItem($normalizedUri);

            $actionAst = $this->acquireActionAst($actionData, $routeData['file'] ?? null, $routeData['line'] ?? null);

            // Parse PHPDoc annotations (FR-001)
            $docMethod = $this->phpDocParser->parseMethodDocblock($actionAst?->node->getDocComment()?->getText());
            $docClass = $this->phpDocParser->parseClassDocblock($actionAst?->classDocComment);

            // Group 1 & PHPDoc Ignored parameters
            $ignoredParamNames = array_unique(array_merge(
                $docMethod['ignoredParams'] ?? [],
                array_column($attrMethod['ignoredParams'] ?? [], 'name')
            ));

            // Infer path parameters (consulting schema_overrides if column missing)
            $pathParams = $this->inferPathParameters($normalizedUri, $actionAst, $dbSchema, $ignoredParamNames, $schemaOverrides);
            $hasModelBinding = count($pathParams['parameters']) > 0;

            // Merge Group 2 parameter attributes for path params if present
            $pathParams['parameters'] = $this->mergeParameterAttributes($pathParams['parameters'], $attrMethod['parameters'] ?? [], 'path');

            // Group 2 attributes placed on a Form Request's own rules() method
            // (e.g. #[BodyParameter] on StoreTeamRequest::rules() rather than on
            // the controller action) apply the same as if declared on the action.
            $formRequestClass = $this->resolveFormRequestClass($actionAst);
            if ($formRequestClass !== null) {
                $formRequestAttrs = $this->attrReader->readMethodAttributes($formRequestClass, 'rules');
                $attrMethod['parameters'] = array_merge($attrMethod['parameters'] ?? [], $formRequestAttrs['parameters'] ?? []);
            }

            // Infer request body & validation rules
            $requestBodyInfo = $actionAst !== null ? $this->inferRequestBody($actionAst, $dbSchema, $ignoredParamNames) : [
                'requestBody' => null,
                'accessors' => [],
                'diagnostics' => [],
            ];
            $hasValidation = $requestBodyInfo['requestBody'] !== null;

            // Merge Group 2 #[BodyParameter] attributes into the inferred request
            // body's per-field schema (description/example/format/default/enum, and
            // infer:false to fully replace a field's inferred schema).
            if ($requestBodyInfo['requestBody'] !== null) {
                $this->mergeBodyParameterAttributes($requestBodyInfo['requestBody'], $attrMethod['parameters'] ?? []);
            }

            // Override request media type if @requestMediaType specified
            if (isset($docMethod['requestMediaType']) && $requestBodyInfo['requestBody'] !== null) {
                $currentMedia = array_key_first($requestBodyInfo['requestBody']->content);
                if ($currentMedia !== null && $currentMedia !== $docMethod['requestMediaType']) {
                    $schema = $requestBodyInfo['requestBody']->content[$currentMedia]['schema'];
                    $requestBodyInfo['requestBody']->content = [
                        $docMethod['requestMediaType'] => ['schema' => $schema],
                    ];
                }
            }

            // Infer response (inferred base)
            $responseInfo = $actionAst !== null ? $this->responseInferer->inferFromActionAst($actionAst, $dbSchema, [], $document->components) : [
                'schema' => new Schema,
                'diagnostics' => [],
            ];

            // Resolve response type via ResponseTypeResolver (FR-007)
            $annotatedResponseSchema = $this->resolveAnnotatedResponseSchema($docMethod, $attrMethod, $actionAst, $document);
            $declaredReturnType = ($actionAst?->node instanceof ClassMethod && $actionAst->node->returnType instanceof Name)
                ? $actionAst->node->returnType->toString()
                : null;

            $final200ResponseSchema = $this->responseResolver->resolve(
                $annotatedResponseSchema,
                $declaredReturnType,
                $responseInfo['schema']
            );

            // Infer error responses (422, 403, 404, aborts, @throws)
            $errorResponses = $this->errorInferer->infer($actionAst, $hasValidation, $hasModelBinding);

            // Filter out ignored responses (IgnoreResponse attribute)
            $ignoredStatuses = $attrMethod['ignoredResponses'] ?? [];
            foreach ($ignoredStatuses as $igStatus) {
                unset($errorResponses[(string) $igStatus]);
            }

            // Security derivation (FR-006, FR-007): every matching per-middleware rule is
            // collected (AND semantics, a route behind two guards, e.g. auth:sanctum AND
            // a custom api-key middleware, requires both), falling back to the single
            // global scheme when no specific rule matches.
            $routeMiddleware = $routeData['middleware'] ?? [];
            $matchedSchemes = [];

            foreach ($schemeRules as $rule) {
                if ($this->middlewareMatches($routeMiddleware, $rule['middleware'] ?? [])) {
                    $matchedSchemes[$rule['name']] = $rule['scheme'];
                }
            }

            $isAuthRoute = ! empty($matchedSchemes)
                || $this->middlewareMatches($routeMiddleware, $authPatterns);

            $isUnauthenticated = ! empty($docMethod['unauthenticated']);

            foreach ($routeData['methods'] as $method) {
                $httpMethod = strtoupper($method);
                if ($httpMethod === 'HEAD') {
                    continue;
                }

                // Summary / title / description / operationId (FR-005)
                $actionSummary = $this->deriveActionSummary($actionAst, $actionData);
                $summary = $attrMethod['endpoint']['title'] ?? $actionSummary ?? $routeData['name'] ?? $normalizedUri;
                $description = $attrMethod['endpoint']['description'] ?? $docMethod['description'] ?? null;
                $operationId = $attrMethod['endpoint']['operationId'] ?? $docMethod['operationId'] ?? null;

                $operation = new Operation($httpMethod, $summary);
                $operation->description = $description;

                // Tags (Class tags, Group attribute, @tags)
                $tags = array_unique(array_merge(
                    $docClass['tags'] ?? [],
                    $docMethod['tags'] ?? [],
                    isset($attrClass['group']['name']) ? [$attrClass['group']['name']] : [],
                    isset($attrMethod['group']['name']) ? [$attrMethod['group']['name']] : []
                ));
                $operation->tags = array_values($tags);

                // Add path parameters
                foreach ($pathParams['parameters'] as $param) {
                    $operation->parameters[] = $param;
                }

                // Add query parameters from request accessors for GET/DELETE (FR-010)
                if (in_array($httpMethod, ['GET', 'DELETE'], true) && ! empty($requestBodyInfo['accessors'])) {
                    foreach ($requestBodyInfo['accessors'] as $paramName => $acc) {
                        if (in_array($paramName, $ignoredParamNames, true)) {
                            continue;
                        }
                        $pSchema = new Schema;
                        $pSchema->type = $acc['type'];
                        if ($acc['default'] !== null) {
                            $pSchema->default = $acc['default'];
                        }
                        $operation->parameters[] = new Parameter(
                            name: $paramName,
                            in: 'query',
                            required: false,
                            schema: $pSchema
                        );
                    }
                }

                // Add query parameters from Spatie QueryBuilder::for(...) for GET requests
                $qbDiagnostics = [];
                if ($httpMethod === 'GET' && $actionAst !== null) {
                    $qbInfo = $this->queryBuilderExtractor->extractFromActionAst($actionAst, $dbSchema);
                    foreach ($qbInfo['parameters'] as $qbParam) {
                        if (in_array($qbParam->name, $ignoredParamNames, true)) {
                            continue;
                        }
                        $operation->parameters[] = $qbParam;
                    }
                    $qbDiagnostics = $qbInfo['diagnostics'];
                }

                // Add / merge Group 2 parameter attributes (QueryParameter, HeaderParameter, CookieParameter)
                $operation->parameters = $this->mergeNonPathParameterAttributes($operation->parameters, $attrMethod['parameters'] ?? [], $httpMethod);

                // Add request body (for non-GET/DELETE)
                if (! in_array($httpMethod, ['GET', 'DELETE'], true) && $requestBodyInfo['requestBody'] !== null) {
                    $operation->requestBody = $requestBodyInfo['requestBody'];
                }

                // Add successful / annotated response
                $targetStatus = $docMethod['response']['status'] ?? $attrMethod['responses'][0]['status'] ?? 200;
                $targetDescription = $docMethod['response']['description'] ?? $attrMethod['responses'][0]['description'] ?? ($targetStatus === 201 ? 'Created' : 'Successful response');

                if ($final200ResponseSchema->properties !== null || $final200ResponseSchema->ref !== null || $final200ResponseSchema->type !== null) {
                    $operation->responses[(string) $targetStatus] = [
                        'description' => $targetDescription,
                        'content' => [
                            'application/json' => [
                                'schema' => $final200ResponseSchema,
                            ],
                        ],
                    ];
                }

                // Add any additional attribute responses
                foreach ($attrMethod['responses'] as $attrResp) {
                    $st = (string) $attrResp['status'];
                    if (! isset($operation->responses[$st])) {
                        $operation->responses[$st] = [
                            'description' => $attrResp['description'] ?? 'Response',
                            'content' => [
                                'application/json' => [
                                    'schema' => $final200ResponseSchema,
                                ],
                            ],
                        ];
                    }
                }

                // Add inferred error responses
                foreach ($errorResponses as $statusStr => $errResp) {
                    $operation->responses[$statusStr] = $errResp;
                }

                // Security requirements (FR-006, FR-007, AC-006)
                if ($securityEnabled && $isAuthRoute && ! $isUnauthenticated) {
                    // No specific rule matched (only the global fallback pattern did), apply the single global scheme.
                    $appliedSchemes = $matchedSchemes !== [] ? $matchedSchemes : [$schemeName => $schemeDef];

                    // One requirement object with every applicable scheme = AND (all required together),
                    // matching a route protected by more than one middleware-derived guard at once.
                    $requirement = [];
                    foreach ($appliedSchemes as $name => $def) {
                        $requirement[$name] = [];
                        $document->components->addSecurityScheme($name, $def);
                    }

                    $operation->security = [$requirement];
                    $operation->responses['401'] = ['description' => 'Unauthenticated'];
                } else {
                    $operation->security = []; // explicitly public
                }

                // Deprecated (class or method @deprecated)
                if (! empty($docMethod['deprecated']) && empty($docMethod['notDeprecated'])) {
                    $operation->deprecated = true;
                }

                // OperationId
                if ($operationId !== null) {
                    $operation->operationId = $operationId;
                }

                // Inert metadata: @unauthenticated, Api(only)
                if (! empty($docMethod['unauthenticated'])) {
                    $operation->extensions['x-unauthenticated'] = true;
                }
                if (! empty($attrMethod['apiOnly']) || ! empty($attrClass['apiOnly'])) {
                    $operation->extensions['x-api'] = $attrMethod['apiOnly'] ?? $attrClass['apiOnly'];
                }

                // Collect diagnostics for x-geni-unresolved (FR-002 / TASK-004)
                $allDiagnostics = array_merge(
                    $pathParams['diagnostics'],
                    $requestBodyInfo['diagnostics'],
                    $responseInfo['diagnostics'],
                    $qbDiagnostics
                );

                if ($allDiagnostics !== []) {
                    $operation->extensions['x-geni-unresolved'] = array_map(fn (InferenceDiagnostic $d) => $d->jsonSerialize(), $allDiagnostics);
                    foreach ($allDiagnostics as $diag) {
                        $this->recordedDiagnostics[] = [
                            'file' => $diag->file,
                            'line' => $diag->line,
                            'reason' => $diag->reason,
                            'operation' => sprintf('%s %s', $httpMethod, $normalizedUri),
                        ];
                    }
                }

                $pathItem->add($operation);
            }

            $document->paths->add($pathItem);
        }

        return $document;
    }

    /**
     * Resolve a Form Request class from the action method's parameter types,
     * so attributes declared on that class's own rules() method (rather than
     * on the controller action itself) are also picked up. Mirrors
     * ValidationRuleExtractor's own Form Request detection/resolution.
     */
    private function resolveFormRequestClass(?ActionAst $actionAst): ?string
    {
        if ($actionAst === null || ! ($actionAst->node instanceof ClassMethod)) {
            return null;
        }

        foreach ($actionAst->node->params as $param) {
            if (! ($param->type instanceof Name)) {
                continue;
            }

            $parts = $param->type->getParts();
            $firstPart = $parts[0];

            if (isset($actionAst->useImports[$firstPart])) {
                $parts[0] = $actionAst->useImports[$firstPart];
                $resolved = implode('\\', $parts);
            } elseif (! empty($actionAst->namespace)) {
                $resolved = $actionAst->namespace.'\\'.implode('\\', $parts);
            } else {
                $resolved = implode('\\', $parts);
            }

            if (str_ends_with($resolved, 'Request') || is_subclass_of($resolved, 'Illuminate\Foundation\Http\FormRequest')) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Merge #[BodyParameter] attributes into the request body's per-field
     * schema properties -- unlike path/query/header params, a body field
     * isn't a top-level Parameter object, it's an entry under the request
     * body schema's `properties`, so it needs its own merge path rather than
     * reusing mergeParameterAttributes()/mergeNonPathParameterAttributes().
     *
     * @param  list<array<string, mixed>>  $attributeParameters
     */
    private function mergeBodyParameterAttributes(RequestBody $requestBody, array $attributeParameters): void
    {
        foreach ($attributeParameters as $attrParam) {
            if (($attrParam['in'] ?? '') !== 'body') {
                continue;
            }
            $name = $attrParam['name'] ?? '';
            if ($name === '') {
                continue;
            }

            foreach ($requestBody->content as $mediaType => $entry) {
                $schema = $entry['schema'] ?? null;
                if (! ($schema instanceof Schema)) {
                    continue;
                }

                $existing = $schema->properties[$name] ?? new Schema;
                $merged = $this->annotationMerger->mergeParameter($existing, $attrParam);
                $schema->properties[$name] = $merged['schema'];

                if ($merged['required'] === true && ! in_array($name, $schema->extensions['required'] ?? [], true)) {
                    $schema->extensions['required'][] = $name;
                } elseif ($merged['required'] === false) {
                    $schema->extensions['required'] = array_values(array_diff($schema->extensions['required'] ?? [], [$name]));
                }

                $requestBody->content[$mediaType]['schema'] = $schema;
            }
        }
    }

    /**
     * @param  list<Parameter>  $parameters
     * @param  list<array<string, mixed>>  $attributeParameters
     * @return list<Parameter>
     */
    private function mergeParameterAttributes(array $parameters, array $attributeParameters, string $targetIn): array
    {
        $byName = [];
        foreach ($parameters as $p) {
            $byName[$p->name] = $p;
        }

        foreach ($attributeParameters as $attrParam) {
            if (($attrParam['in'] ?? '') !== $targetIn) {
                continue;
            }
            $name = $attrParam['name'] ?? '';
            if ($name === '') {
                continue;
            }

            if (isset($byName[$name])) {
                $merged = $this->annotationMerger->mergeParameter($byName[$name]->schema, $attrParam);
                $byName[$name]->schema = $merged['schema'];
                if (isset($merged['required'])) {
                    $byName[$name]->required = $merged['required'];
                }
                if (isset($attrParam['description'])) {
                    $byName[$name]->description = $attrParam['description'];
                }
            } else {
                $s = new Schema;
                $merged = $this->annotationMerger->mergeParameter($s, $attrParam);
                $byName[$name] = new Parameter(
                    name: $name,
                    in: $targetIn,
                    required: $merged['required'] ?? true,
                    schema: $merged['schema']
                );
            }
        }

        return array_values($byName);
    }

    /**
     * @param  list<Parameter>  $parameters
     * @param  list<array<string, mixed>>  $attributeParameters
     * @return list<Parameter>
     */
    private function mergeNonPathParameterAttributes(array $parameters, array $attributeParameters, string $httpMethod): array
    {
        foreach ($attributeParameters as $attrParam) {
            $in = $attrParam['in'] ?? '';
            if ($in === 'path' || $in === 'body') {
                continue;
            }
            $name = $attrParam['name'] ?? '';
            if ($name === '') {
                continue;
            }

            $s = new Schema;
            $merged = $this->annotationMerger->mergeParameter($s, $attrParam);
            $p = new Parameter(
                name: $name,
                in: $in,
                required: $merged['required'] ?? false,
                schema: $merged['schema']
            );
            if (isset($attrParam['description'])) {
                $p->description = $attrParam['description'];
            }
            $parameters[] = $p;
        }

        return $parameters;
    }

    /**
     * Resolve explicit response schema from @response PHPDoc or #[Response] attribute.
     */
    private function resolveAnnotatedResponseSchema(
        array $docMethod,
        array $attrMethod,
        ?ActionAst $actionAst,
        OpenApiDocument $document
    ): ?Schema {
        // Priority to @response tag
        if (isset($docMethod['response']['type'])) {
            $typeStr = $docMethod['response']['type'];

            return $this->resolveTypeStringToSchema($typeStr, $actionAst, $document);
        }

        // #[Response] attribute
        if (! empty($attrMethod['responses'])) {
            $resp = $attrMethod['responses'][0];
            if (isset($resp['type']) && is_string($resp['type'])) {
                return $this->resolveTypeStringToSchema($resp['type'], $actionAst, $document);
            }
        }

        return null;
    }

    private function resolveTypeStringToSchema(string $typeStr, ?ActionAst $actionAst, OpenApiDocument $document): Schema
    {
        $clean = ltrim($typeStr, '\\');

        // Resolve a short class name against the action's own `use` imports first
        // (e.g. `@response TeamResource` where the file has `use App\Http\Resources\TeamResource;`),
        // matching Scramble's behavior, before falling back to the raw string.
        $resolved = $actionAst?->useImports[$clean] ?? $clean;

        // Check if it's a class
        if (class_exists($resolved)) {
            $refName = substr(strrchr('\\'.$resolved, '\\') ?: $resolved, 1);
            $refSchema = new Schema;
            $refSchema->ref = '#/components/schemas/'.$refName;

            return $refSchema;
        }

        // Check scalar types
        $lower = strtolower($clean);
        $s = new Schema;
        $s->type = match ($lower) {
            'int', 'integer' => 'integer',
            'float', 'numeric' => 'number',
            'bool', 'boolean' => 'boolean',
            'array' => 'array',
            default => 'string',
        };

        return $s;
    }

    /**
     * @param  array<string, mixed>  $actionData
     */
    private function acquireActionAst(array $actionData, ?string $file = null, ?int $line = null): ?ActionAst
    {
        $type = $actionData['type'] ?? 'unknown';

        if ($type === 'controller' && isset($actionData['class'], $actionData['method'])) {
            return $this->astAcquirer->fromControllerMethod(
                $actionData['class'],
                $actionData['method'],
                $file
            );
        }

        if ($type === 'closure' && isset($actionData['file'])) {
            return $this->astAcquirer->fromClosure(
                $actionData['file'],
                $actionData['line'] ?? $line
            );
        }

        return null;
    }

    /**
     * @param  list<string>  $ignoredParamNames
     * @param  array<string, string>  $schemaOverrides
     * @return array{parameters: list<Parameter>, diagnostics: list<InferenceDiagnostic>}
     */
    private function inferPathParameters(
        string $uri,
        ?ActionAst $actionAst,
        ?DatabaseSchema $dbSchema,
        array $ignoredParamNames = [],
        array $schemaOverrides = []
    ): array {
        $paramNames = $this->pathInferer->extractParamNames($uri);
        $parameters = [];
        $diagnostics = [];

        foreach ($paramNames as $name) {
            if (in_array($name, $ignoredParamNames, true)) {
                continue;
            }

            $inferResult = $this->pathInferer->inferParam(
                paramName: $name,
                schema: $dbSchema,
                boundModelClass: null,
                file: $actionAst?->file ?? 'unknown',
                line: $actionAst?->line ?? 0,
                schemaOverrides: $schemaOverrides
            );

            $parameters[] = $inferResult['parameter'];
            foreach ($inferResult['diagnostics'] as $d) {
                $diagnostics[] = $d;
            }
        }

        return [
            'parameters' => $parameters,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @param  list<string>  $ignoredParamNames
     * @return array{
     *     requestBody: ?RequestBody,
     *     accessors: array<string, array{type: string, default: mixed}>,
     *     diagnostics: list<InferenceDiagnostic>
     * }
     */
    private function inferRequestBody(ActionAst $actionAst, ?DatabaseSchema $dbSchema, array $ignoredParamNames = []): array
    {
        $extracted = $this->ruleExtractor->extractFromActionAst($actionAst);

        // Filter out ignored / hidden fields
        if ($ignoredParamNames !== []) {
            foreach ($ignoredParamNames as $igName) {
                unset($extracted['fields'][$igName]);
            }
        }

        if ($extracted['fields'] === []) {
            return [
                'requestBody' => null,
                'accessors' => $extracted['accessors'] ?? [],
                'diagnostics' => $extracted['diagnostics'],
            ];
        }

        $mapped = $this->schemaMapper->map(
            $extracted['fields'],
            $actionAst->file,
            $actionAst->line,
            $dbSchema
        );

        if (! empty($extracted['schemas']) && $mapped['schema']->properties !== null) {
            foreach ($extracted['schemas'] as $propName => $propSchema) {
                if ($propSchema instanceof Schema) {
                    $mapped['schema']->properties[$propName] = $propSchema;
                }
            }
        }

        // Filter out ignored / hidden properties from schema if any leaked through
        if ($ignoredParamNames !== [] && $mapped['schema']->properties !== null) {
            foreach ($ignoredParamNames as $igName) {
                unset($mapped['schema']->properties[$igName]);
            }
        }

        // FR-006 / TASK-009: multipart/form-data media type when file/image present
        $mediaType = $mapped['hasFile'] ? 'multipart/form-data' : 'application/json';

        $body = new RequestBody;
        $body->content[$mediaType] = [
            'schema' => $mapped['schema'],
        ];

        return [
            'requestBody' => $body,
            'accessors' => $extracted['accessors'] ?? [],
            'diagnostics' => array_merge($extracted['diagnostics'], $mapped['diagnostics']),
        ];
    }

    /**
     * Derive a human-readable operation summary from an Action class name.
     *
     * @param  array<string, mixed>  $actionData
     */
    private function deriveActionSummary(?ActionAst $actionAst, array $actionData): ?string
    {
        $className = $actionData['class'] ?? null;
        if (! is_string($className) || $className === '') {
            return null;
        }

        if ($actionAst !== null && $actionAst->node instanceof ClassMethod) {
            $detector = new LaravelActionDetector;
            $parsed = $detector->parseFileAndExtractClass($actionAst->file, $className);
            $classNode = $parsed['class'];
            if ($classNode === null || ! $detector->isActionClass($classNode, $actionAst->useImports)) {
                return null;
            }
        } else {
            return null;
        }

        $shortClass = substr(strrchr('\\'.$className, '\\') ?: $className, 1);

        if (str_ends_with($shortClass, 'Action')) {
            $baseName = substr($shortClass, 0, -6);
        } elseif (str_ends_with($shortClass, 'Controller')) {
            $baseName = substr($shortClass, 0, -10);
        } else {
            $baseName = $shortClass;
        }

        if ($baseName === '') {
            return null;
        }

        $words = preg_split('/(?=[A-Z])/', $baseName, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || empty($words)) {
            return null;
        }

        $first = array_shift($words);
        $rest = array_map('strtolower', $words);

        return $first.(count($rest) > 0 ? ' '.implode(' ', $rest) : '');
    }

    /**
     * Whether any of the route's middleware matches any of the given patterns.
     * A pattern ending in ':*' matches any middleware sharing that prefix
     * (e.g. 'auth:*' matches 'auth:sanctum', 'auth:api', ...).
     *
     * @param  list<string>  $routeMiddleware
     * @param  list<string>  $patterns
     */
    private function middlewareMatches(array $routeMiddleware, array $patterns): bool
    {
        foreach ($routeMiddleware as $mw) {
            foreach ($patterns as $pat) {
                if ($pat === $mw || (str_ends_with($pat, ':*') && str_starts_with($mw, substr($pat, 0, -1)))) {
                    return true;
                }
            }
        }

        return false;
    }
}
