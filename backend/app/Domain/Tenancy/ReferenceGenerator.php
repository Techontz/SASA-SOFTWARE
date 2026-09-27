<?php

namespace App\Domain\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Allocates per-project reference numbers under a row lock.
 *
 * Two field officers whose devices sync at the same instant must not receive
 * GRV-0042 twice, so the counter is read FOR UPDATE inside a transaction.
 */
final class ReferenceGenerator
{
    public function next(int $projectId, string $key): string
    {
        $format = config("sasa.reference_ids.$key");

        if (! $format) {
            throw new \InvalidArgumentException("No reference format configured for [$key].");
        }

        $value = $this->allocate($projectId, $key);

        return sprintf('%s-%s', $format['prefix'], str_pad((string) $value, $format['pad'], '0', STR_PAD_LEFT));
    }

    private function allocate(int $projectId, string $key): int
    {
        $run = function () use ($projectId, $key): int {
            $row = DB::table('sequences')
                ->where('project_id', $projectId)
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                DB::table('sequences')->insert([
                    'project_id' => $projectId,
                    'key' => $key,
                    'next_value' => 2,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return 1;
            }

            DB::table('sequences')
                ->where('id', $row->id)
                ->update(['next_value' => $row->next_value + 1, 'updated_at' => now()]);

            return (int) $row->next_value;
        };

        // Reuse the caller's transaction when there is one; the lock is held
        // until that outer transaction commits, which is exactly right.
        return DB::transactionLevel() > 0 ? $run() : DB::transaction($run, 3);
    }

    public function peek(int $projectId, string $key): int
    {
        return (int) (DB::table('sequences')
            ->where('project_id', $projectId)
            ->where('key', $key)
            ->value('next_value') ?? 1);
    }
}
