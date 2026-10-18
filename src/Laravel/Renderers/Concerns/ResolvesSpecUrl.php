<?php

declare(strict_types=1);

namespace Geni\Laravel\Renderers\Concerns;

use Geni\Laravel\Controllers\DocumentationController;

/**
 * URL of the JSON document the UI should load: the current API's JSON route.
 */
trait ResolvesSpecUrl
{
    /**
     * @param  array<string, mixed>  $config
     * @return array{api: string, url: string}
     */
    protected function specUrl(array $config): array
    {
        $currentApi = (string) ($config['current_api'] ?? 'default');
        $availableApis = $config['available_apis'] ?? app(DocumentationController::class)->getAvailableApis($currentApi);

        foreach ($availableApis as $api) {
            if ($api['key'] === $currentApi) {
                return ['api' => $currentApi, 'url' => $api['json_url']];
            }
        }

        return ['api' => $currentApi, 'url' => url(config('geni.docs_json_path', 'docs/api.json'))];
    }
}
