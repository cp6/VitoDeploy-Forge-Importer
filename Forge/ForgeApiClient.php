<?php

namespace App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Forge;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ForgeApiClient
{
    public function __construct(private readonly string $token) {}

    public function get(string $path, array $query = []): array
    {
        $response = $this->request()->get($this->url($path), $query);
        $this->assertSuccessful($response);

        return $response->json() ?? [];
    }

    public function optional(string $path, array $query = []): ?array
    {
        $response = $this->request()->get($this->url($path), $query);

        if (in_array($response->status(), [403, 404, 405], true)) {
            return null;
        }

        $this->assertSuccessful($response);

        return $response->json() ?? [];
    }

    public function paginate(string $path, array $query = []): array
    {
        $items = [];
        $next = $this->url($path);

        do {
            $request = $this->request();
            $response = $query === [] ? $request->get($next) : $request->get($next, $query);
            $this->assertSuccessful($response);
            $payload = $response->json() ?? [];
            $page = $payload['data'] ?? $payload;

            if (is_array($page)) {
                $items = array_merge($items, array_is_list($page) ? $page : [$page]);
            }

            $next = data_get($payload, 'links.next');
            $query = [];
            if (is_string($next) && $next !== '' && ! str_starts_with($next, 'http')) {
                $next = $this->paginationUrl($next);
            }
        } while (is_string($next) && $next !== '');

        return $items;
    }

    public function optionalPaginate(string $path, array $query = []): array
    {
        try {
            return $this->paginate($path, $query);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), '(HTTP 403)')
                || str_contains($e->getMessage(), '(HTTP 404)')
                || str_contains($e->getMessage(), '(HTTP 405)')) {
                return [];
            }

            throw $e;
        }
    }

    public static function resource(array $resource): array
    {
        $attributes = $resource['attributes'] ?? [];
        if (! is_array($attributes)) {
            $attributes = [];
        }

        $relationships = $resource['relationships'] ?? [];

        return array_merge($attributes, [
            'id' => (string) ($resource['id'] ?? $attributes['id'] ?? ''),
            '_relationships' => is_array($relationships) ? $relationships : [],
        ]);
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->withToken($this->token)
            ->timeout((int) config('forge-import.request_timeout', 25))
            ->retry(3, 500, throw: false);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('forge-import.base_url'), '/').'/'.ltrim($path, '/');
    }

    private function paginationUrl(string $path): string
    {
        $baseUrl = rtrim((string) config('forge-import.base_url'), '/');
        $basePath = parse_url($baseUrl, PHP_URL_PATH) ?: '';

        if ($basePath !== '' && str_starts_with($path, $basePath.'/')) {
            $origin = substr($baseUrl, 0, -strlen($basePath));

            return rtrim($origin, '/').'/'.ltrim($path, '/');
        }

        return $baseUrl.'/'.ltrim($path, '/');
    }

    private function assertSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $message = data_get($response->json(), 'message')
            ?? data_get($response->json(), 'errors.0.detail')
            ?? 'Forge API request failed';

        throw new RuntimeException($message.' (HTTP '.$response->status().')');
    }
}
