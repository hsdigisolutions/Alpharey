<?php

namespace App\Enums;

/**
 * Payment progress. Used both for a worker line (pending → partial → paid) and
 * for a single scheduled payment (pending → paid; partial does not apply there).
 */
enum SubcontractorPaymentStatus: string
{
    case Pending = 'pending';
    case Partial = 'partial';
    case Paid = 'paid';
}
