<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class Audit
{
    public static function log(string $action, ?Model $entity = null, array $changes = []): void
    {
        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => $entity ? $entity::class : null,
            'auditable_id' => $entity?->getKey(),
            'changes' => $changes ?: null,
            'ip_address' => Request::ip(),
        ]);
    }
}
