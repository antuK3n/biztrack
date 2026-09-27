<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Every mutating action records an audit row (master plan §5.2). Call
 * Audit::log('application.submitted', $application, ['status' => 'submitted']).
 */
class Audit
{
    /**
     * `$actorId` is for the one moment nobody is signed in yet but we know who
     * is acting: the sign-in itself. Without it a sign-in row carries a null
     * actor and the audit screen shows it as the system's doing.
     */
    public static function log(string $action, ?Model $entity = null, array $changes = [], ?int $actorId = null): void
    {
        AuditLog::create([
            'user_id' => $actorId ?? Auth::id(),
            'action' => $action,
            'auditable_type' => $entity ? $entity::class : null,
            'auditable_id' => $entity?->getKey(),
            'changes' => $changes ?: null,
            'ip_address' => Request::ip(),
        ]);
    }
}
