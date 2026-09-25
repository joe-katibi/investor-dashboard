<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('oms:create-admin {--name=} {--username=} {--email=}')]
#[Description('Create a Super Admin account (use instead of demo credentials in production)')]
class CreateAdminCommand extends Command
{
    public function handle(): int
    {
        $data = [
            'name' => $this->option('name') ?: text('Full name', required: true),
            'username' => $this->option('username') ?: text('Username', required: true),
            'email' => $this->option('email') ?: text('Email', required: true),
            'password' => password('Password', required: true),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'alpha_dash', 'max:60', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::query()->create($validator->validated() + [
            'is_active' => true,
            'department_id' => Department::query()->where('code', 'MGMT')->value('id'),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole('Super Admin');

        $this->info("Super Admin {$user->email} created.");

        return self::SUCCESS;
    }
}
