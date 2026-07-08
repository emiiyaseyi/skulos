<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ProvisionSchool extends Command
{
    protected $signature = 'school:provision
        {name : Display name of the school}
        {--slug= : Subdomain slug (defaults to a slug of the name)}
        {--email= : School contact email}
        {--phone= : School contact phone}';

    protected $description = 'Create a school record, its dedicated database, and run tenant migrations';

    public function handle(TenantManager $tenants): int
    {
        $name = $this->argument('name');
        $slug = $this->option('slug') ?: Str::slug($name);

        if (School::where('slug', $slug)->exists()) {
            $this->error("A school with slug [{$slug}] already exists.");

            return self::FAILURE;
        }

        $school = School::create([
            'name' => $name,
            'slug' => $slug,
            'email' => $this->option('email'),
            'phone' => $this->option('phone'),
        ]);

        $tenants->provision($school);

        $this->info("School [{$school->name}] provisioned.");
        $this->line("  Slug:     {$school->slug}");
        $this->line("  Database: {$school->database}");

        return self::SUCCESS;
    }
}
