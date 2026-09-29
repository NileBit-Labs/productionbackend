<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\Shop;
use App\Models\User;
use App\Models\UserShopRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class InviteOwner extends Command
{
    protected $signature = 'beta:invite-owner
        {--name= : Invited owner full name}
        {--email= : Invited owner email address}
        {--organization= : Organization name}
        {--shop= : First shop name}
        {--business-type=small_shop : Shop business type}
        {--phone= : Optional shop phone number}
        {--address= : Optional shop address}';

    protected $description = 'Create an invited owner, organization, and first shop without issuing an API token';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('This command requires an interactive terminal so the password is never passed as a command argument.');

            return self::FAILURE;
        }

        $data = [
            'name' => $this->option('name') ?? $this->ask('Owner name'),
            'email' => $this->option('email') ?? $this->ask('Owner email'),
            'organization' => $this->option('organization') ?? $this->ask('Organization name'),
            'shop' => $this->option('shop') ?? $this->ask('First shop name'),
            'business_type' => $this->option('business-type'),
            'phone' => $this->option('phone'),
            'address' => $this->option('address'),
            'password' => $this->secret('Temporary password (minimum 8 characters)'),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'organization' => ['required', 'string', 'max:255'],
            'shop' => ['required', 'string', 'max:255'],
            'business_type' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        [$user, $organization, $shop] = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'status' => 'active',
            ]);

            $organization = Organization::create([
                'name' => $data['organization'],
                'owner_user_id' => $user->id,
            ]);

            $user->update(['organization_id' => $organization->id]);

            $shop = Shop::create([
                'organization_id' => $organization->id,
                'name' => $data['shop'],
                'business_type' => $data['business_type'],
                'phone' => $data['phone'],
                'address' => $data['address'],
            ]);

            UserShopRole::create([
                'user_id' => $user->id,
                'shop_id' => $shop->id,
                'role' => Role::Owner,
            ]);

            return [$user, $organization, $shop];
        });

        $this->info("Invited owner created for {$organization->name}.");
        $this->line("Owner: {$user->email}");
        $this->line("Shop: {$shop->name} (ID {$shop->id})");
        $this->comment('No API token was issued. Share the temporary password through an approved secure channel.');

        return self::SUCCESS;
    }
}
