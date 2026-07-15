<?php

namespace App\Enums;

enum MeasurementType: string
{
    case Length = 'length';
    case Area = 'area';
    case Volume = 'volume';
    case Weight = 'weight';
}
