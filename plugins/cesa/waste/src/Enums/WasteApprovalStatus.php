<?php

namespace Cesa\Waste\Enums;

enum WasteApprovalStatus: string
{
    case Waiting = 'waiting';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
