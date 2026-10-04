<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationStatusHistory extends Model
{
    protected $table = 'application_status_history';

    protected $fillable = [
        'application_id', 'permit_type_id', 'from_status', 'to_status',
        'changed_by_user_id', 'department_id', 'note',
    ];

    /**
     * Which permit this row is about, or null for the FILING's own move.
     *
     * One table, two timelines, told apart by this column — see the
     * migration that added it for why it is not a second table.
     */
    public function permitType(): BelongsTo
    {
        return $this->belongsTo(PermitType::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
