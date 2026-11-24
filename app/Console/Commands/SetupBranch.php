<?php

namespace App\Console\Commands;

use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SetupBranch extends Command
{
    protected $signature = 'endu:setup-branch
        {organization : Organization code}
        {branch : Branch code}
        {--organization-name= : Display name of the organization}
        {--branch-name= : Display name of the branch}';

    protected $description = 'Create an organization and its branch for ENDU CARE';

    public function handle(): int
    {
        $data = Validator::make([
            'organization' => Str::lower(trim($this->argument('organization'))),
            'branch' => Str::lower(trim($this->argument('branch'))),
            'organization_name' => trim((string) $this->option('organization-name')),
            'branch_name' => trim((string) $this->option('branch-name')),
        ], [
            'organization' => ['required', 'alpha_dash:ascii', 'max:32'],
            'branch' => ['required', 'alpha_dash:ascii', 'max:32'],
            'organization_name' => ['required', 'string', 'max:150'],
            'branch_name' => ['required', 'string', 'max:150'],
        ])->validate();

        $branch = DB::transaction(function () use ($data) {
            $organization = Organization::firstOrCreate(
                ['code' => $data['organization']],
                ['name' => $data['organization_name']],
            );

            return $organization->branches()->firstOrCreate(
                ['code' => $data['branch']],
                ['name' => $data['branch_name']],
            );
        });

        $this->info("พร้อมใช้งาน {$data['organization']}/{$branch->code} : {$branch->name}");

        return self::SUCCESS;
    }
}
