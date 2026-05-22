<?php
namespace App\Enum;

enum AiReportStatus: string
{
    case Pending = 'pending';
    case Done    = 'done';
    case Failed  = 'failed';
}
