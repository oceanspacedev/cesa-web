<?php

namespace Cesa\Waste\Enums;

enum WasteAlternateUnitCandidateStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
