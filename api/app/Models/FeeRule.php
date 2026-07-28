<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeeRule extends Model
{
    protected $fillable = [
        'code', 'title', 'section', 'source', 'office', 'group', 'permit_types',
        'conditions', 'basis', 'computation', 'cap', 'notes', 'defects',
        'constants', 'requires_officer', 'active',
    ];

    protected $casts = [
        'permit_types' => 'array',
        'conditions' => 'array',
        'computation' => 'array',
        'cap' => 'array',
        'defects' => 'array',
        'constants' => 'array',
        'requires_officer' => 'boolean',
        'active' => 'boolean',
    ];
}
