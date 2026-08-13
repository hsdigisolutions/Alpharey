<?php

namespace App\Enums;

/**
 * Who bears the project expenses on a subcontracted (thaekedar) deal.
 *
 *   Thaekedar (Scenario A, most common): expenses come out of HIS budget —
 *     they reduce his settlement, never our profit.
 *   Ours (Scenario B): we pay some expenses directly — they reduce OUR
 *     profit and stay out of his settlement.
 */
enum ExpenseResponsibility: string
{
    case Thaekedar = 'thaekedar';
    case Ours = 'ours';
}
