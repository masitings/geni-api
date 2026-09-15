<?php

declare(strict_types=1);

namespace Geni\Inference;

use ReflectionClass;
use ReflectionMethod;

/**
 * Reads PHP 8 attributes using reflection without executing attribute bodies.
 *
 * Supports both Geni\Laravel\Attributes\* and Dedoc\Scramble\Attributes\* (for drop-in compatibility).
 *
 * Framework-free: Core reflection only.
 */
final class AttributeAnnotationReader
{
    /**
     * Read method-level attributes.
     *
     * @return array{
     *     excludeRoute?: bool,
     *     endpoint?: array{operationId?: ?string, title?: ?string, description?: ?string, method?: ?string},
     *     group?: array{name: string, description?: ?string, weight?: ?int},
     *     parameters: list<array{in: string, name: string, description?: ?string, required?: ?bool, deprecated?: ?bool, type?: mixed, format?: ?string, infer?: bool, default?: mixed, example?: mixed, examples?: ?array}>,
     *     responses: list<array{status: int, description?: ?string, mediaType?: ?string, type?: mixed, format?: ?string, examples?: ?array}>,
     *     ignoredParams: list<array{name: string, in?: ?string}>,
     *     ignoredResponses: list<int>,
     *     apiOnly?: list<string>
     * }
     */
    public function readMethodAttributes(string $class, string $method): array
    {
        $result = [
            'parameters' => [],
            'responses' => [],
            'ignoredParams' => [],
            'ignoredResponses' => [],
        ];

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return $result;
        }

        try {
            $refMethod = new ReflectionMethod($class, $method);
            $attributes = $refMethod->getAttributes();
        } catch (\Throwable) {
            return $result;
        }

        foreach ($attributes as $attr) {
            $name = $this->getShortAttributeName($attr->getName());
            $args = $attr->getArguments();

            // Group 1: Markers
            if ($name === 'ExcludeRouteFromDocs') {
                $result['excludeRoute'] = true;

                continue;
            }

            if ($name === 'IgnoreParam') {
                $result['ignoredParams'][] = [
                    'name' => $args['name'] ?? ($args[0] ?? ''),
                    'in' => $args['in'] ?? ($args[1] ?? null),
                ];

                continue;
            }

            if ($name === 'IgnoreResponse') {
                $result['ignoredResponses'][] = (int) ($args['status'] ?? ($args[0] ?? 200));

                continue;
            }

            // Group 2: Parameters
            $paramIn = match ($name) {
                'QueryParameter' => 'query',
                'HeaderParameter' => 'header',
                'PathParameter' => 'path',
                'BodyParameter' => 'body',
                'CookieParameter' => 'cookie',
                default => null,
            };

            if ($paramIn !== null) {
                $paramData = $this->extractParameterAttributeData($paramIn, $args);
                $result['parameters'][] = $paramData;

                continue;
            }

            // Group 3: Metadata
            if ($name === 'Endpoint') {
                $result['endpoint'] = [
                    'operationId' => $args['operationId'] ?? ($args[0] ?? null),
                    'title' => $args['title'] ?? ($args[1] ?? null),
                    'description' => $args['description'] ?? ($args[2] ?? null),
                    'method' => $args['method'] ?? ($args[3] ?? null),
                ];

                continue;
            }

            if ($name === 'Group') {
                $result['group'] = [
                    'name' => $args['name'] ?? ($args[0] ?? ''),
                    'description' => $args['description'] ?? ($args[1] ?? null),
                    'weight' => $args['weight'] ?? ($args[2] ?? null),
                ];

                continue;
            }

            if ($name === 'Response') {
                $result['responses'][] = [
                    'status' => (int) ($args['status'] ?? ($args[0] ?? 200)),
                    'description' => $args['description'] ?? ($args[1] ?? null),
                    'mediaType' => $args['mediaType'] ?? ($args[2] ?? null),
                    'type' => $args['type'] ?? ($args[3] ?? null),
                    'format' => $args['format'] ?? ($args[4] ?? null),
                    'examples' => $args['examples'] ?? ($args[5] ?? null),
                ];

                continue;
            }

            if ($name === 'Api') {
                $only = $args['only'] ?? ($args[0] ?? []);
                $result['apiOnly'] = is_array($only) ? $only : [$only];

                continue;
            }
        }

        return $result;
    }

    /**
     * Read class-level attributes.
     *
     * @return array{
     *     excludeAll?: bool,
     *     group?: array{name: string, description?: ?string, weight?: ?int},
     *     schemaName?: string,
     *     apiOnly?: list<string>
     * }
     */
    public function readClassAttributes(string $class): array
    {
        $result = [];

        if (! class_exists($class)) {
            return $result;
        }

        try {
            $refClass = new ReflectionClass($class);
            $attributes = $refClass->getAttributes();
        } catch (\Throwable) {
            return $result;
        }

        foreach ($attributes as $attr) {
            $name = $this->getShortAttributeName($attr->getName());
            $args = $attr->getArguments();

            if ($name === 'ExcludeAllRoutesFromDocs') {
                $result['excludeAll'] = true;

                continue;
            }

            if ($name === 'Group') {
                $result['group'] = [
                    'name' => $args['name'] ?? ($args[0] ?? ''),
                    'description' => $args['description'] ?? ($args[1] ?? null),
                    'weight' => $args['weight'] ?? ($args[2] ?? null),
                ];

                continue;
            }

            if ($name === 'SchemaName') {
                $result['schemaName'] = $args['name'] ?? ($args[0] ?? '');

                continue;
            }

            if ($name === 'Api') {
                $only = $args['only'] ?? ($args[0] ?? []);
                $result['apiOnly'] = is_array($only) ? $only : [$only];

                continue;
            }
        }

        return $result;
    }

    /**
     * Map positional or named constructor arguments to a clean parameter array.
     */
    private function extractParameterAttributeData(string $in, array $args): array
    {
        return [
            'in' => $in,
            'name' => $args['name'] ?? ($args[0] ?? ''),
            'description' => $args['description'] ?? ($args[1] ?? null),
            'required' => $args['required'] ?? ($args[2] ?? null),
            'deprecated' => $args['deprecated'] ?? ($args[3] ?? null),
            'type' => $args['type'] ?? ($args[4] ?? null),
            'format' => $args['format'] ?? ($args[5] ?? null),
            'infer' => $args['infer'] ?? ($args[6] ?? true),
            'default' => $args['default'] ?? ($args[7] ?? null),
            'example' => $args['example'] ?? ($args[8] ?? null),
            'examples' => $args['examples'] ?? ($args[9] ?? null),
        ];
    }

    /**
     * Get short class name without leading namespace.
     */
    private function getShortAttributeName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }
}
