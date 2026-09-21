<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PsicCode extends Model
{
    protected $fillable = ['code', 'title', 'category', 'permit_category', 'category_branch'];
}
