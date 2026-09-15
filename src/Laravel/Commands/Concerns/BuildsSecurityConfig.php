<?php

declare(strict_types=1);

namespace Geni\Laravel\Commands\Concerns;

/**
 * Shared securityConfig array builder for every command that assembles a
 * document. Converts config('geni.middleware_security_schemes') — an
 * associative map of middleware pattern => scheme — into the ordered
 * 'rules' list DocumentAssembler checks before its single global scheme,
 * so different middleware (e.g. auth:sanctum vs a custom API-key guard)
 * can document as different security schemes automatically.
 */
trait BuildsSecurityConfig
{
    /**
     * @return array<string, mixed>
     */
    protected function buildSecurityConfig(): array
    {
        $rules = [];

        foreach (config('geni.middleware_security_schemes', []) as $middleware => $definition) {
            $rules[] = [
                'middleware' => [$middleware],
                'name' => $definition['name'],
                'scheme' => $definition['scheme'],
            ];
        }

        return [
            'enabled' => true,
            'middleware' => config('geni.middleware_security', ['auth', 'auth:*']),
            'scheme_name' => 'bearerAuth',
            'scheme' => config('geni.security_scheme', []),
            'rules' => $rules,
        ];
    }
}
