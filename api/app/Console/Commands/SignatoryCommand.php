<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\OfficeSignatory;
use App\Support\Audit;
use Illuminate\Console\Command;

/**
 * Show or change one office signatory, e.g. the City Mayor who signs every
 * certificate:
 *
 *   php artisan biztrack:signatory BPLO "City Mayor"                    # show
 *   php artisan biztrack:signatory BPLO "City Mayor" "Hon. New Mayor"   # change
 *
 * Permits already issued keep the name they were signed with (it is frozen on
 * the permit); the next one issued prints the new name.
 */
class SignatoryCommand extends Command
{
    protected $signature = 'biztrack:signatory {office : The office code, e.g. BPLO} {role : The role as printed, e.g. "City Mayor"} {name? : The new name; leave out to show the current one}';

    protected $description = 'Show or change the name an office signatory prints as, such as the City Mayor on certificates';

    public function handle(): int
    {
        $department = Department::query()->where('code', $this->argument('office'))->first();
        if (! $department) {
            $this->error('No office with the code '.$this->argument('office').'.');

            return self::FAILURE;
        }

        $role = (string) $this->argument('role');
        $row = OfficeSignatory::query()->where('department_id', $department->id)->where('role', $role)->first();
        $name = $this->argument('name');

        if ($name === null) {
            $this->line($row ? "{$department->code} {$role}: {$row->name}".($row->is_active ? '' : ' (inactive)') : "{$department->code} has no {$role} on file.");

            return self::SUCCESS;
        }

        $name = trim((string) $name);
        if ($name === '') {
            $this->error('Give the name as it should print, or leave it out to see the current one.');

            return self::FAILURE;
        }

        $before = $row?->name;
        $row = OfficeSignatory::query()->updateOrCreate(
            ['department_id' => $department->id, 'role' => $role],
            ['name' => $name, 'is_active' => true],
        );

        Audit::log('signatory.updated', $row, ['before' => $before, 'after' => $name, 'via' => 'console']);
        $this->info("{$department->code} {$role} now prints as {$name}. Permits already issued keep the name they were signed with.");

        return self::SUCCESS;
    }
}
