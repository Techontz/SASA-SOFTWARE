<?php

namespace App\Domain\Configuration;

use App\Domain\Audit\AuditLogger;
use App\Models\Configuration;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Three-layer configuration resolution:
 *
 *   project row  >  organisation row  >  config/sasa.php
 *
 * "Anything that differs between projects is configuration; anything that is
 * the same everywhere is code." Organisation defaults exist precisely so that
 * cross-project reporting stays possible.
 */
final class ConfigurationRegistry
{
    private const CACHE_TTL = 300;

    public function __construct(private readonly AuditLogger $audit) {}

    public function get(string $key, ?int $organisationId, ?int $projectId = null, mixed $default = null): mixed
    {
        $resolved = $this->resolved($organisationId, $projectId);

        if (array_key_exists($key, $resolved)) {
            return $resolved[$key];
        }

        // Fall through to the file defaults, supporting dot access.
        $fileDefault = config('sasa.'.$key, $default);

        if ($fileDefault !== null) {
            return $fileDefault;
        }

        foreach ($resolved as $storedKey => $value) {
            if (str_starts_with($key, $storedKey.'.')) {
                return Arr::get($value, substr($key, strlen($storedKey) + 1), $default);
            }
        }

        return $default;
    }

    /** @return array<string,mixed> */
    public function all(?int $organisationId, ?int $projectId = null): array
    {
        return $this->resolved($organisationId, $projectId);
    }

    public function set(string $key, mixed $value, int $organisationId, ?int $projectId = null): Configuration
    {
        $existing = Configuration::query()
            ->where('organisation_id', $organisationId)
            ->where('project_id', $projectId)
            ->where('key', $key)
            ->first();

        $before = $existing?->value;

        $configuration = Configuration::updateOrCreate(
            ['organisation_id' => $organisationId, 'project_id' => $projectId, 'key' => $key],
            ['value' => $value, 'updated_by' => auth()->id()],
        );

        $this->forget($organisationId, $projectId);

        $this->audit->record(
            action: 'configuration.changed',
            entity: $configuration,
            before: ['key' => $key, 'value' => $before],
            after: ['key' => $key, 'value' => $value],
            summary: "Configuration '$key' updated",
            projectId: $projectId,
            organisationId: $organisationId,
        );

        return $configuration;
    }

    public function forget(?int $organisationId, ?int $projectId = null): void
    {
        Cache::forget($this->cacheKey($organisationId, null));
        Cache::forget($this->cacheKey($organisationId, $projectId));
    }

    /** @return array<string,mixed> */
    private function resolved(?int $organisationId, ?int $projectId): array
    {
        if (! $organisationId) {
            return [];
        }

        $organisationLayer = Cache::remember(
            $this->cacheKey($organisationId, null),
            self::CACHE_TTL,
            fn () => Configuration::query()
                ->where('organisation_id', $organisationId)
                ->whereNull('project_id')
                ->pluck('value', 'key')
                ->all()
        );

        if (! $projectId) {
            return $organisationLayer;
        }

        $projectLayer = Cache::remember(
            $this->cacheKey($organisationId, $projectId),
            self::CACHE_TTL,
            fn () => Configuration::query()
                ->where('organisation_id', $organisationId)
                ->where('project_id', $projectId)
                ->pluck('value', 'key')
                ->all()
        );

        return array_merge($organisationLayer, $projectLayer);
    }

    private function cacheKey(?int $organisationId, ?int $projectId): string
    {
        return sprintf('sasa.config.%s.%s', $organisationId ?? 'none', $projectId ?? 'org');
    }
}
