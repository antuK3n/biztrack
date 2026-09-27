<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Every mutating action records an audit row (master plan §5.2). Call
 * Audit::log('application.submitted', $application, ['status' => 'submitted']).
 *
 * A REMOVAL — a delete, or a retire that takes a record out of use — calls
 * Audit::removed() instead, which also keeps the record itself (see below).
 */
class Audit
{
    public static function log(string $action, ?Model $entity = null, array $changes = [], ?array $snapshot = null): void
    {
        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => $entity ? $entity::class : null,
            'auditable_id' => $entity?->getKey(),
            'changes' => $changes ?: null,
            'snapshot' => $snapshot,
            'ip_address' => Request::ip(),
        ]);
    }

    /**
     * Log a removal and keep a full copy of what was removed.
     *
     * Ken's checklist, 27 September 2026, "Audit Log 1 — keep removed records".
     * Call it BEFORE the delete or the retiring update, while the row still
     * says what it said: a snapshot taken afterwards records the retired state,
     * which is the one thing nobody needs a copy of.
     *
     * `$with` names child relations to copy alongside — a draft's documents,
     * a business's address — because a row without its children is often not
     * enough to say what was lost. Relations are loaded here if the caller has
     * not already loaded them.
     *
     * The snapshot is `attributesToArray()`, which honours `$hidden`, so a
     * user's password hash and remember token never reach the audit log.
     *
     * @param  list<string>  $with
     */
    public static function removed(string $action, Model $entity, array $changes = [], array $with = []): void
    {
        self::log($action, $entity, $changes, self::snapshot($entity, $with));
    }

    /**
     * The record as a plain array: its own columns plus the named relations.
     *
     * @param  list<string>  $with
     * @return array<string, mixed>
     */
    public static function snapshot(Model $entity, array $with = []): array
    {
        $copy = $entity->attributesToArray();

        foreach ($with as $relation) {
            if (! $entity->relationLoaded($relation)) {
                $entity->load($relation);
            }
            $related = $entity->getRelation($relation);

            $copy[$relation] = match (true) {
                $related instanceof EloquentCollection => $related->map(fn (Model $m) => $m->attributesToArray())->all(),
                $related instanceof Model => $related->attributesToArray(),
                default => null,
            };
        }

        return $copy;
    }
}
