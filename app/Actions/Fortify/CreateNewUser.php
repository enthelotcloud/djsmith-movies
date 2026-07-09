<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Models\Subscription;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'phone' => ['required', 'string', Rule::unique(User::class)],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            
            // Validate the referral code exists in the users table (if one was typed in)
            'referral_code' => ['nullable', 'string', 'exists:users,referral_code'],
        ])->validate();

        // Check if a valid referral code was passed, and grab that user's ID
        $referredBy = null;
        if (!empty($input['referral_code'])) {
            $referrer = User::where('referral_code', $input['referral_code'])->first();
            if ($referrer) {
                $referredBy = $referrer->id;
            }
        }

        // Create the user
        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'phone' => $input['phone'],
            'password' => Hash::make($input['password']),
            'role' => 'client', // Default public signups to client
            
            // Generate a unique 8-character referral code for this new user
            'referral_code' => Str::upper(Str::random(8)), 
            
            // Log who referred them
            'referred_by' => $referredBy, 
        ]);

        // Setup the initial inactive subscription
        Subscription::create([
            'user_id' => $user->id,
            'status' => 'inactive',
            'auto_renew' => false,
        ]);

        return $user;
    }
}