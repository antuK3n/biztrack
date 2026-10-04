<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PsicCode extends Model
{
    /**
     * The catch-all row: "Other (not listed)", the escape hatch an applicant
     * picks when their trade is not in the list.
     *
     * It carries no classification — `PsicTaxonomy::group` returns null for it
     * on purpose — so a line filed under it is described ONLY by the free text
     * beside it, which is why `BusinessController` requires that text and why
     * `BusinessLine::tradeName` prefers it.
     *
     * Lives on the model rather than in ReferenceSeeder, which declared it
     * first. A rule the request validator enforces cannot have its definition
     * in a seeder: that is a class the application does not otherwise load, and
     * importing it into a controller to ask a question about live data reads
     * as though the answer came from fixtures.
     */
    public const UNCLASSIFIED = '00000';

    protected $fillable = ['code', 'title', 'category', 'permit_category', 'category_branch'];
}
