<?php

declare(strict_types=1);

namespace Geni\Laravel\Commands\Concerns;

use Geni\Laravel\DocumentGenerator;

/**
 * Reads the `--api` option shared by every geni:* command and rejects names
 * that are neither "default" nor a key of config('geni.apis').
 */
trait ResolvesApiOption
{
    /**
     * @return string|null the API name, or null after printing an error when it is not configured
     */
    protected function resolveApiOption(): ?string
    {
        $apiName = (string) ($this->option('api') ?: 'default');

        if (! DocumentGenerator::apiExists($apiName)) {
            $this->error(sprintf('Unknown API "%s". Use "default" or a key of config(\'geni.apis\').', $apiName));

            return null;
        }

        return $apiName;
    }
}
