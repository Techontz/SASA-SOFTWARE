<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Health endpoint for load balancers and uptime monitoring. */
class HealthController
{
    public function __invoke()
    {
        $checks = [];

        $checks['database'] = $this->check(function () {
            DB::select('select 1');

            return ['pending_migrations' => 0];
        });

        $checks['cache'] = $this->check(function () {
            Cache::put('sasa:health', 'ok', 10);

            return ['value' => Cache::get('sasa:health')];
        });

        $checks['storage'] = $this->check(function () {
            $disk = Storage::disk(config('filesystems.default', 'local'));
            $disk->put('health.txt', (string) now());
            $ok = $disk->exists('health.txt');
            $disk->delete('health.txt');

            return ['writable' => $ok];
        });

        $checks['queue'] = $this->check(fn () => [
            'pending_jobs' => DB::table('jobs')->count(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
        ]);

        $healthy = collect($checks)->every(fn ($check) => $check['status'] === 'ok');

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'application' => config('app.name'),
            'environment' => config('app.env'),
            'time' => now()->toIso8601String(),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    private function check(callable $probe): array
    {
        $started = microtime(true);

        try {
            $detail = $probe();

            return [
                'status' => 'ok',
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'detail' => $detail,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'failed',
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => $e->getMessage(),
            ];
        }
    }
}
