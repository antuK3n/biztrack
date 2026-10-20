<?php

namespace App\Enums;

enum ApplicationType: string
{
    case New = 'new';
    case Renewal = 'renewal';
    case Amendment = 'amendment';

    /*
     * Not a filing anybody made. The system opens one on the day an Occupancy
     * Permit's yearly inspection falls due (client, 20 October 2026: *"only
     * annual inspection instead of annual renewal"*; App\Support\AnnualInspection).
     * It carries the OCCUPANCY clearance already at For Inspection, so OBO
     * works it with the regular inspection actions and rulings. No fee, no
     * wizard, and no new permit on a pass. Kept out of the application counts
     * through NOT_FILINGS.
     */
    case AnnualInspection = 'annual_inspection';

    /**
     * Types that are not applications somebody filed, for the counts that
     * mean "filings" (analytics, reports, processing time).
     */
    public const NOT_FILINGS = ['annual_inspection'];

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Renewal => 'Renewal',
            self::Amendment => 'Amendment',
            self::AnnualInspection => 'Annual inspection',
        };
    }

    /** Was this filed by somebody, rather than opened by the system? */
    public function isFiling(): bool
    {
        return ! in_array($this->value, self::NOT_FILINGS, true);
    }
}
