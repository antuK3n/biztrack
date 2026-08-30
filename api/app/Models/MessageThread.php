<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One conversation, between the applicant on a filing and ONE office.
 *
 * The pair `(application_id, department_id)` is the identity of a thread and is
 * unique in the schema. Before this it was `application_id` alone, which is why
 * "make sure the business owner can only contact the correct offices" had
 * nothing to enforce: a message had no addressee. See the migration
 * 2026_08_30_000010 for the reasoning and for what happened to the 520 threads
 * that predate the column.
 */
class MessageThread extends Model
{
    protected $fillable = ['application_id', 'department_id'];

    /**
     * A thread with no office named is BPLO's.
     *
     * The same assumption the backfill makes, held here so it is true of new
     * rows as well as old ones — seeders, factories and any caller that has not
     * been taught about the column all produce a correctly addressed thread
     * instead of a null one. BPLO coordinates every filing and is who an
     * applicant writes to when they do not know which office to ask, so it is
     * the only defensible default; a null department would be a thread nobody
     * is answerable for, and the read paths would have to guess.
     *
     * Resolved by CODE, never by a hard-coded id: department ids are seed data
     * and differ between the register and a fresh test database.
     */
    protected static function booted(): void
    {
        static::creating(function (self $thread) {
            if ($thread->department_id === null) {
                $thread->department_id = Department::where('code', 'BPLO')->value('id');
            }
        });
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** The office on the other side of this conversation. */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'thread_id')->orderBy('created_at');
    }
}
