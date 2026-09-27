<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One import of the old register: its dry run, then its run.
 *
 * See the 2026_09_27_000110 migration for why this is a table as well as an
 * audit row. Status runs previewed → queued → running → completed | failed.
 */
class LegacyImport extends Model
{
    public const PREVIEWED = 'previewed';

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    protected $fillable = [
        'user_id', 'source', 'file_name', 'stored_path', 'source_query', 'status',
        'total_rows', 'will_create', 'will_update', 'rejected', 'breakdown', 'rejects',
        'created_count', 'updated_count', 'processed_rows', 'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'breakdown' => 'array',
        'rejects' => 'array',
        'source_query' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
