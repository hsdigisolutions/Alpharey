<?php

namespace App\Enums;

enum EquipmentIssueStatus: string
{
    case Open = 'open';
    case PartiallyReturned = 'partially_returned';
    case Returned = 'returned';
}
