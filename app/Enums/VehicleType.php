<?php

namespace App\Enums;

enum VehicleType: string
{
    case Car = 'car';
    case Van = 'van';
    case Truck = 'truck';
    case Motorcycle = 'motorcycle';
    case Other = 'other';
}
