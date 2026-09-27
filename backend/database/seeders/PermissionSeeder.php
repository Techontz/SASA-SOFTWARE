<?php

namespace Database\Seeders;

use App\Domain\Identity\PermissionCatalogue;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Permissions and the platform-wide role templates. Safe to re-run: it
 * upserts, so adding a permission to the catalogue and re-seeding grants it to
 * the roles that should have it without disturbing anything else.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionCatalogue::permissions() as $definition) {
            Permission::updateOrCreate(['key' => $definition['key']], $definition);
        }

        $permissionIds = Permission::pluck('id', 'key');

        foreach (PermissionCatalogue::roles() as $definition) {
            $role = Role::updateOrCreate(
                ['organisation_id' => null, 'key' => $definition['key']],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'escalation_rank' => $definition['escalation_rank'],
                    'is_system' => true,
                ]
            );

            $role->permissions()->sync(
                collect($definition['permissions'])
                    ->map(fn (string $key) => $permissionIds[$key] ?? null)
                    ->filter()
                    ->values()
                    ->all()
            );
        }

        $this->command?->info('Seeded '.Permission::count().' permissions and '.Role::whereNull('organisation_id')->count().' role templates.');
    }
}
