<?php

namespace App\Support;

use App\Models\Application;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class ApplicationVisibility
{
    public const ANY_OFFICE = 'application.view_any_office';

    public const VIEW_ALL = 'application.view_all';

    public static function readsEveryOffice(User $user): bool
    {
        return $user->hasPermission(self::ANY_OFFICE);
    }

    public static function readsOwnOffice(User $user): bool
    {
        return ! self::readsEveryOffice($user) && $user->hasPermission(self::VIEW_ALL);
    }

    public static function canView(User $user, Application $application): bool
    {
        if ($application->applicant_user_id === $user->id) {
            return true;
        }
        if (self::readsEveryOffice($user)) {
            return true;
        }
        if (! $user->hasPermission(self::VIEW_ALL) || ! $user->department_id) {
            return false;
        }

        return $application->assignments()
            ->where('department_id', $user->department_id)
            ->exists();
    }

    public static function authorize(User $user, Application $application, ?string $message = null): void
    {
        abort_unless(
            self::canView($user, $application),
            403,
            $message ?? 'This application belongs to another office.'
        );
    }

    public static function scope(Builder $query, User $user, ?string $relation = null): void
    {
        if (self::readsEveryOffice($user)) {
            return;
        }

        $constrain = function (Builder $app) use ($user) {
            if (! $user->hasPermission(self::VIEW_ALL)) {
                $app->where('applicant_user_id', $user->id);

                return;
            }
            if (! $user->department_id) {
                $app->whereRaw('1 = 0');

                return;
            }
            $app->where(function (Builder $sub) use ($user) {
                $sub->where('applicant_user_id', $user->id)
                    ->orWhereHas('assignments', fn (Builder $a) => $a->where('department_id', $user->department_id));
            });
        };

        if ($relation === null) {
            $constrain($query);

            return;
        }

        $query->whereHas($relation, $constrain);
    }
}
