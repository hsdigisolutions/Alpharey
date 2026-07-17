<?php

namespace App\Enums;

/**
 * Screen 21 — who owns the vehicle. `Employee` means the worker's own car is
 * used for work; it is still tracked, but the fleet does not own it.
 */
enum VehicleOwnership: string
{
    case Company = 'company';
    case Employee = 'employee';
}
