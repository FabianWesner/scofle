<?php

namespace App;

enum AttemptStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Ready = 'ready';
    case Failed = 'failed';

    public function isInflight(): bool
    {
        return in_array($this, [self::Pending, self::Running], true);
    }
}
