<?php

namespace App\Enums;

enum SyncStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Partial = 'partial';
    case Failed = 'failed';

    public function color(): string
    {
        return match ($this) {
            self::Running => 'info',
            self::Success => 'success',
            self::Partial => 'warning',
            self::Failed => 'danger',
        };
    }
}
