<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Actions\Reporting\EnsureReportingFoundationAction;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Phase7\Phase7TestCase;

abstract class Phase8TestCase extends Phase7TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(EnsureReportingFoundationAction::class)->execute($this->company);
        setPermissionsTeamId($this->company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $this->owner->unsetRelation('roles')->unsetRelation('permissions');
    }

    protected function activateUser(User $user): void
    {
        setPermissionsTeamId($this->company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $this->activate($user);
    }
}
