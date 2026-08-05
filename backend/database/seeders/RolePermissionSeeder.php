<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
    private const PERMISSIONS = [
        'inboxes.manage' => 'Create, edit and deactivate inboxes',
        'agents.manage' => 'Manage agent profiles and availability',
        'teams.manage' => 'Manage teams and team membership',
        'routing.manage' => 'Manage routing rules',
        'webhooks.manage' => 'Manage webhook endpoints and view deliveries',
        'faqs.manage' => 'Create, edit and delete FAQ entries',
        'faqs.view' => 'Browse FAQ entries and quick replies',
        'canned_responses.manage' => 'Create, edit and delete canned responses',
        'conversations.view_own' => 'View conversations assigned to self',
        'conversations.view_all' => 'View all conversations',
        'conversations.accept' => 'Accept offered conversations',
        'conversations.assign' => 'Assign or reroute conversations',
        'conversations.close' => 'Close conversations',
        'messages.send' => 'Send messages in conversations',
        'analytics.view' => 'View dashboards and reports',
        'license.manage' => 'View and manage the installation license',
        'settings.manage' => 'View and edit chat settings',
        'system.manage' => 'Edit system settings (branding, data retention) and view system health',
    ];

    /**
     * @var array<string, list<string>|string>
     */
    private const ROLES = [
        'admin' => '*',
        'supervisor' => [
            'agents.manage',
            'teams.manage',
            'faqs.manage',
            'faqs.view',
            'canned_responses.manage',
            'conversations.view_own',
            'conversations.view_all',
            'conversations.accept',
            'conversations.assign',
            'conversations.close',
            'messages.send',
            'analytics.view',
        ],
        'agent' => [
            'faqs.view',
            'conversations.view_own',
            'conversations.accept',
            'conversations.close',
            'messages.send',
        ],
    ];

    public function run(): void
    {
        $permissions = collect(self::PERMISSIONS)
            ->map(fn (string $description, string $name) => Permission::updateOrCreate(
                ['name' => $name],
                ['description' => $description],
            ));

        foreach (self::ROLES as $roleName => $grants) {
            $role = Role::firstOrCreate(['name' => $roleName]);

            $granted = $grants === '*'
                ? $permissions
                : $permissions->only($grants);

            $role->permissions()->sync($granted->pluck('id'));
        }
    }
}
