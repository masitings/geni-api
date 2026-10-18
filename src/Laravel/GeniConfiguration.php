<?php

declare(strict_types=1);

namespace Geni\Laravel;

use Closure;
use Geni\Inference\ExceptionToResponse;
use Geni\Inference\RuleTransformer;
use Geni\Laravel\Contracts\DocumentTransformer;
use Geni\Laravel\Contracts\OperationTransformer;
use InvalidArgumentException;

/**
 * Extensions registered for one documented API through `Geni::configure()`.
 *
 * Each `with*()` method accepts instances, class names (resolved from the
 * container) or closures, either as separate arguments or as one array.
 */
final class GeniConfiguration
{
    /** @var list<DocumentTransformer|Closure|class-string<DocumentTransformer>> */
    private array $documentTransformers = [];

    /** @var list<OperationTransformer|Closure|class-string<OperationTransformer>> */
    private array $operationTransformers = [];

    /** @var list<RuleTransformer|class-string<RuleTransformer>> */
    private array $ruleTransformers = [];

    /** @var list<ExceptionToResponse|class-string<ExceptionToResponse>> */
    private array $exceptionMappers = [];

    /**
     * Change the whole document: `fn (OpenApiDocument $document, string $apiName) => ...`.
     *
     * @param  DocumentTransformer|Closure|class-string<DocumentTransformer>|array<int, DocumentTransformer|Closure|class-string<DocumentTransformer>>  ...$transformers
     */
    public function withDocumentTransformers(mixed ...$transformers): self
    {
        array_push($this->documentTransformers, ...$this->flatten($transformers));

        return $this;
    }

    /**
     * Same as a document transformer, under the name many projects already use for this hook.
     */
    public function afterOpenApiGenerated(Closure $callback): self
    {
        return $this->withDocumentTransformers($callback);
    }

    /**
     * Change each operation: `fn (Operation $operation, DiscoveredRoute $route, string $apiName) => ...`.
     *
     * @param  OperationTransformer|Closure|class-string<OperationTransformer>|array<int, OperationTransformer|Closure|class-string<OperationTransformer>>  ...$transformers
     */
    public function withOperationTransformers(mixed ...$transformers): self
    {
        array_push($this->operationTransformers, ...$this->flatten($transformers));

        return $this;
    }

    /**
     * Map custom validation rules (e.g. `ipv4_cidr`, or a Rule object's string form) to schema changes.
     *
     * @param  RuleTransformer|class-string<RuleTransformer>|array<int, RuleTransformer|class-string<RuleTransformer>>  ...$transformers
     */
    public function withRuleTransformers(mixed ...$transformers): self
    {
        array_push($this->ruleTransformers, ...$this->flatten($transformers));

        return $this;
    }

    /**
     * Map application exceptions (from `@throws`) to HTTP status codes.
     *
     * @param  ExceptionToResponse|class-string<ExceptionToResponse>|array<int, ExceptionToResponse|class-string<ExceptionToResponse>>  ...$mappers
     */
    public function withExceptionResponses(mixed ...$mappers): self
    {
        array_push($this->exceptionMappers, ...$this->flatten($mappers));

        return $this;
    }

    /**
     * Register a class by the extension interface(s) it implements; used for
     * `config('geni.extensions')`.
     *
     * @param  class-string|object  $extension
     */
    public function withExtension(string|object $extension): self
    {
        $matched = false;

        foreach ([
            DocumentTransformer::class => 'documentTransformers',
            OperationTransformer::class => 'operationTransformers',
            RuleTransformer::class => 'ruleTransformers',
            ExceptionToResponse::class => 'exceptionMappers',
        ] as $interface => $list) {
            if (is_a($extension, $interface, true)) {
                $this->{$list}[] = $extension;
                $matched = true;
            }
        }

        if (! $matched) {
            throw new InvalidArgumentException(sprintf(
                'Geni extension %s must implement DocumentTransformer, OperationTransformer, RuleTransformer or ExceptionToResponse.',
                is_string($extension) ? $extension : $extension::class
            ));
        }

        return $this;
    }

    /**
     * A new configuration holding this one's extensions followed by the other's.
     */
    public function merge(self $other): self
    {
        $merged = new self;
        $merged->documentTransformers = array_merge($this->documentTransformers, $other->documentTransformers);
        $merged->operationTransformers = array_merge($this->operationTransformers, $other->operationTransformers);
        $merged->ruleTransformers = array_merge($this->ruleTransformers, $other->ruleTransformers);
        $merged->exceptionMappers = array_merge($this->exceptionMappers, $other->exceptionMappers);

        return $merged;
    }

    /**
     * @return list<DocumentTransformer|Closure>
     */
    public function documentTransformers(): array
    {
        return array_map(fn ($transformer) => $this->resolve($transformer, DocumentTransformer::class), $this->documentTransformers);
    }

    /**
     * @return list<OperationTransformer|Closure>
     */
    public function operationTransformers(): array
    {
        return array_map(fn ($transformer) => $this->resolve($transformer, OperationTransformer::class), $this->operationTransformers);
    }

    /**
     * @return list<RuleTransformer>
     */
    public function ruleTransformers(): array
    {
        return array_map(fn ($transformer) => $this->resolve($transformer, RuleTransformer::class), $this->ruleTransformers);
    }

    /**
     * @return list<ExceptionToResponse>
     */
    public function exceptionMappers(): array
    {
        return array_map(fn ($mapper) => $this->resolve($mapper, ExceptionToResponse::class), $this->exceptionMappers);
    }

    /**
     * @param  array<int, mixed>  $items
     * @return list<mixed>
     */
    private function flatten(array $items): array
    {
        $flat = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                array_push($flat, ...$this->flatten($item));
            } else {
                $flat[] = $item;
            }
        }

        return $flat;
    }

    private function resolve(mixed $extension, string $interface): object
    {
        if ($extension instanceof Closure && $interface !== RuleTransformer::class && $interface !== ExceptionToResponse::class) {
            return $extension;
        }

        $instance = is_string($extension) ? app($extension) : $extension;

        if (! $instance instanceof $interface) {
            throw new InvalidArgumentException(sprintf(
                'Geni extension %s must implement %s.',
                is_object($instance) ? $instance::class : (string) $extension,
                $interface
            ));
        }

        return $instance;
    }
}
