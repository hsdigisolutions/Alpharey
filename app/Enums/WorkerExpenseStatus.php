<?php

namespace App\Enums;

enum WorkerExpenseStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case InReview = 'in_review';
}
