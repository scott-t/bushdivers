<?php

namespace App\Models\Enums;

enum RoleNames: string
{
    // Permissions
    case FLEET_MANAGER = 'fleet_manager';
    case FLEET_ADMIN = 'fleet_admin';
    case AIRPORT_MANAGER = 'airport_manager';
    case TOUR_ADMIN = 'tour_admin';
    case DISPATCHER = 'dispatcher';

    // Unused for now
    case RESOURCE_MANAGER = 'resource_manager';

    // Other flags for temporary feature enablement
    case AI_DISPATCH = 'ai_dispatch';
}
