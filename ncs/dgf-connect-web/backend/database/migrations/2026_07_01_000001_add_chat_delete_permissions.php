<?php

use App\Enums\PermissionName;
use App\Enums\RoleId;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds dedicated permissions for deleting chat messages and conversations (chat + support),
 * replacing the previous Global Admin role check. Both are granted to the Global Admin role by
 * default; other roles can be granted them from the role management screen.
 */
return new class extends Migration {
    private const PERMISSIONS = [PermissionName::ChatDeleteMessage, PermissionName::ChatDeleteConversation];

    public function up(): void
    {
        $globalAdmin = Role::find(RoleId::GlobalAdmin->value);

        foreach (self::PERMISSIONS as $permission) {
            $model = Permission::query()->firstOrCreate(
                ['name' => $permission->value],
                ['display_name' => $permission->value]
            );

            $globalAdmin?->permissions()->syncWithoutDetaching([$model->id]);
        }
    }

    public function down(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            $model = Permission::query()->where('name', $permission->value)->first();
            if ($model === null) {
                continue;
            }

            $model->roles()->detach();
            $model->delete();
        }
    }
};
