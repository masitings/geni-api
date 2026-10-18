<?php

declare(strict_types=1);

namespace Geni\Laravel;

/**
 * Holds the `Geni::configure()` registrations of the running application.
 * Bound as a singleton, so registrations live as long as the app instance.
 */
final class GeniConfigurationRegistry
{
    /** @var array<string, GeniConfiguration> */
    private array $configurations = [];

    public function for(string $apiName): GeniConfiguration
    {
        return $this->configurations[$apiName] ??= new GeniConfiguration;
    }

    /**
     * Everything that applies to an API: `geni.extensions`, then the API's own
     * `geni.apis.{name}.extensions`, then `Geni::configure()` for `default`
     * (shared by every API), then for the API itself.
     */
    public function resolved(string $apiName): GeniConfiguration
    {
        $fromConfig = new GeniConfiguration;

        foreach ((array) config('geni.extensions', []) as $extension) {
            $fromConfig->withExtension($extension);
        }

        if ($apiName !== 'default') {
            foreach ((array) config("geni.apis.{$apiName}.extensions", []) as $extension) {
                $fromConfig->withExtension($extension);
            }
        }

        $resolved = $fromConfig->merge($this->for('default'));

        return $apiName === 'default' ? $resolved : $resolved->merge($this->for($apiName));
    }
}
