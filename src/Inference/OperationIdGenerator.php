<?php

declare(strict_types=1);

namespace Geni\Inference;

use Geni\Inference\Document\Operation;

/**
 * Gives every operation without an explicit id a unique `operationId`, using
 * Scramble's rules: the route name (without a leading `api.`) when it is
 * unique, otherwise the last two parts of the controller + method (for
 * example `post.index`), then the full controller path, then `name_index`.
 * Closures without a route name get no id.
 */
final class OperationIdGenerator
{
    private const IGNORED_PARTS = ['app', 'http', 'api', 'controllers', 'invoke'];

    /** @var list<array{operation: Operation, eloquent: ?string, unique: list<string>, closure: bool}> */
    private array $candidates = [];

    /**
     * @param  array{type?: string, class?: string, method?: string}  $action
     */
    public function add(Operation $operation, ?string $routeName, array $action, bool $primaryMethod): void
    {
        if ($operation->operationId !== null) {
            return;
        }

        $eloquent = null;
        if ($routeName !== null && $routeName !== '' && ! str_contains($routeName, 'generated::')) {
            $eloquent = str_starts_with($routeName, 'api.') ? substr($routeName, 4) : $routeName;
        }

        $class = (string) ($action['class'] ?? '');
        if (str_ends_with($class, 'Controller')) {
            $class = substr($class, 0, -10);
        }

        $parts = array_merge(explode('\\', $class), [(string) ($action['method'] ?? '')]);
        $unique = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $part = $part === strtoupper($part) ? strtolower($part) : lcfirst(str_replace(['_', '-', ' '], '', ucwords($part, '_- ')));
            if (! in_array(strtolower($part), self::IGNORED_PARTS, true)) {
                $unique[] = $part;
            }
        }

        // PATCH next to PUT on the same route (Laravel's `update`) needs its own id.
        if (! $primaryMethod) {
            $suffix = strtolower($operation->method);
            $eloquent = $eloquent !== null ? $eloquent.'_'.$suffix : null;
            $unique[] = $suffix;
        }

        $this->candidates[] = [
            'operation' => $operation,
            'eloquent' => $eloquent,
            'unique' => $unique,
            'closure' => ($action['type'] ?? null) !== 'controller' || ($action['class'] ?? '') === '',
        ];
    }

    public function assign(): void
    {
        $eloquentCounts = [];
        $shortCounts = [];
        $fullCounts = [];

        foreach ($this->candidates as $candidate) {
            $eloquentCounts[$candidate['eloquent'] ?? ''] = ($eloquentCounts[$candidate['eloquent'] ?? ''] ?? 0) + 1;
            $shortCounts[$this->short($candidate)] = ($shortCounts[$this->short($candidate)] ?? 0) + 1;
            $fullCounts[$this->full($candidate)] = ($fullCounts[$this->full($candidate)] ?? 0) + 1;
        }

        foreach ($this->candidates as $index => $candidate) {
            if ($candidate['eloquent'] === null && $candidate['closure']) {
                continue;
            }

            $candidate['operation']->operationIdGenerated = true;
            $candidate['operation']->operationId = match (true) {
                $candidate['eloquent'] !== null && $eloquentCounts[$candidate['eloquent']] === 1 => $candidate['eloquent'],
                $this->short($candidate) !== '' && $shortCounts[$this->short($candidate)] === 1 => $this->short($candidate),
                $this->full($candidate) !== '' && $fullCounts[$this->full($candidate)] === 1 => $this->full($candidate),
                default => $this->full($candidate).'_'.$index,
            };
        }

        $this->candidates = [];
    }

    /**
     * @param  array{unique: list<string>}  $candidate
     */
    private function short(array $candidate): string
    {
        return implode('.', array_slice($candidate['unique'], -2));
    }

    /**
     * @param  array{unique: list<string>}  $candidate
     */
    private function full(array $candidate): string
    {
        return implode('.', $candidate['unique']);
    }
}
