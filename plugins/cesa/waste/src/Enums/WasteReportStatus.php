<?php

namespace Cesa\Waste\Enums;

enum WasteReportStatus: string
{
    case Pending = 'pending';
    case Rejected = 'rejected';
    case Approved = 'approved';
}
