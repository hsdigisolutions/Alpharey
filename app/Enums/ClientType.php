<?php

namespace App\Enums;

enum ClientType: string
{
    case Company = 'company';
    case Private = 'private';
    case Municipality = 'municipality';
    case Other = 'other';
}
