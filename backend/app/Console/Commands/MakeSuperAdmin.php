<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Support\TemporaryPassword;

class MakeSuperAdmin extends Command
{
    protected $signature = 'platform:make-super-admin
        {email : The super admin email}
        {--name= : Display name}
        {--password= : Password (a random one is generated if omitted)}';

    protected $description = 'Create or promote a platform super administrator';

    public function handle(): int
    {
        $email = Str::lower($this->argument('email'));
        $name = $this->option('name') ?: Str::headline(Str::before($email, '@'));
        $password = $this->option('password') ?: TemporaryPassword::generate(16);
        $generated = ! $this->option('password');

        $user = User::firstOrNew(['email' => $email]);
        $user->name = $user->name ?: $name;
        if (! $user->exists || $generated || $this->option('password')) {
            $user->password = Hash::make($password);
        }
        $user->email_verified_at = $user->email_verified_at ?: now();
        $user->save();
        $user->forceFill(['is_super_admin' => true])->save();

        $this->info("Super admin ready: {$email}");
        if ($generated) {
            $this->warn("Generated password: {$password}");
            $this->line('Store it now; it will not be shown again.');
        }

        return self::SUCCESS;
    }
}
