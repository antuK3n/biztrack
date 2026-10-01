<?php

namespace App\Support\LegacyImport;

use App\Models\Barangay;
use App\Models\Business;
use App\Models\PermitType;
use Illuminate\Support\Facades\DB;

/**
 * What the register already holds, loaded once per import.
 *
 * Validating a 40,000-row export row by row against the database would be
 * 40,000 × several queries. Instead the handful of facts a row is checked
 * against — barangay names, permit types, which legacy ids and numbers are
 * taken — are read up front into arrays, and the importer keeps them current
 * as it writes, so row 900 sees the business row 12 created.
 */
class Lookups
{
    /** @var array<string, int> normalised name => barangay id */
    public array $barangays = [];

    /** @var array<string, int> normalised code or name => permit type id */
    public array $permitTypes = [];

    /** @var array<string, array{id: int, claimed: bool}> legacy id => business */
    public array $businesses = [];

    /** @var array<string, array{id: int, business_id: int, business_legacy_id: string|null}> legacy id => permit */
    public array $permits = [];

    /** @var array<string, string> normalised permit number => its legacy id, '' when BizTrack minted it */
    public array $permitNumbers = [];

    /** @var array<string, string> business account no. => its legacy id, '' when BizTrack minted it */
    public array $bans = [];

    /** @var array<string, int> lower-cased email => an active business owner account */
    public array $ownerAccounts = [];

    public static function load(): self
    {
        $l = new self;

        foreach (Barangay::query()->get(['id', 'name', 'code']) as $b) {
            $l->barangays[self::key($b->name)] = $b->id;
            if (filled($b->code)) {
                $l->barangays[self::key($b->code)] = $b->id;
            }
        }

        foreach (PermitType::query()->get(['id', 'code', 'name']) as $t) {
            $l->permitTypes[self::key($t->code)] = $t->id;
            $l->permitTypes[self::key($t->name)] = $t->id;
        }

        Business::withTrashed()->whereNotNull('legacy_id')
            ->get(['id', 'legacy_id', 'owner_user_id'])
            ->each(function (Business $b) use ($l) {
                $l->businesses[$b->legacy_id] = ['id' => $b->id, 'claimed' => $b->owner_user_id !== null];
            });

        DB::table('permits')
            ->leftJoin('businesses', 'businesses.id', '=', 'permits.business_id')
            ->whereNotNull('permits.legacy_id')
            ->select('permits.id', 'permits.legacy_id', 'permits.business_id', 'businesses.legacy_id as business_legacy_id')
            ->get()
            ->each(function ($p) use ($l) {
                $l->permits[$p->legacy_id] = [
                    'id' => (int) $p->id,
                    'business_id' => (int) $p->business_id,
                    'business_legacy_id' => $p->business_legacy_id,
                ];
            });

        foreach (DB::table('permits')->select('permit_number', 'legacy_id')->cursor() as $p) {
            $l->permitNumbers[self::number($p->permit_number)] = (string) ($p->legacy_id ?? '');
        }

        foreach (DB::table('businesses')->whereNotNull('ban')->select('ban', 'legacy_id')->cursor() as $b) {
            $l->bans[self::number($b->ban)] = (string) ($b->legacy_id ?? '');
        }

        DB::table('users')
            ->join('user_roles', 'user_roles.user_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('roles.name', 'business_owner')
            ->where('users.is_active', true)
            ->whereNull('users.deleted_at')
            ->select('users.id', 'users.email')
            ->get()
            ->each(function ($u) use ($l) {
                $l->ownerAccounts[strtolower(trim($u->email))] = (int) $u->id;
            });

        return $l;
    }

    /**
     * A name reduced to what identifies it: lower case, no accents, no
     * punctuation or spacing, and no "Barangay"/"Brgy." in front. "Tañong",
     * "TANONG" and "Brgy. Tañong" are one barangay; "Bayan-bayanan" and
     * "Bayan Bayanan" are one too.
     */
    public static function key(?string $value): string
    {
        $v = mb_strtolower(trim((string) $value));
        $v = strtr($v, ['ñ' => 'n', 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
        $v = preg_replace('/^(barangay|brgy\.?)\s+/u', '', $v) ?? $v;

        return preg_replace('/[^a-z0-9]/', '', $v) ?? '';
    }

    /** A certificate or account number compared without case or surrounding space. */
    public static function number(?string $value): string
    {
        return mb_strtoupper(trim((string) $value));
    }
}
