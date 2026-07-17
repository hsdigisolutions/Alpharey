<?php

namespace App\Enums;

/**
 * employee_vehicle_assignments.type — `Own` records a worker driving their own
 * car for work (vehicle_id is null: it is not in the fleet).
 */
enum VehicleAssignmentType: string
{
    case Own = 'own';
    case Company = 'company';
}
