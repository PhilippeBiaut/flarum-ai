<?php

namespace Pbiaut\AiSeeder\Creator;

use DateTimeInterface;
use Flarum\User\User;
use Illuminate\Support\Str;
use Pbiaut\AiSeeder\Service\SeederSettings;

/**
 * Creates a member directly on the model, on purpose.
 *
 * User::register() raises the Registered event but never dispatches it - that
 * is the job of the command handler we deliberately bypass. Going through
 * RegisterUser instead would run validation, throttling and, more importantly,
 * release that event, which sends welcome and confirmation mail. Seeding a
 * hundred members must not send a hundred emails.
 */
class UserCreator
{
    public function __construct(protected SeederSettings $settings)
    {
    }

    /**
     * @param  array<string, mixed>  $persona
     */
    public function create(array $persona, DateTimeInterface $joinedAt): User
    {
        $username = $this->uniqueUsername((string) ($persona['username'] ?? ''));

        $user = User::register(
            $username,
            $this->uniqueEmail($username),
            Str::random(32)
        );

        // register() stamps joined_at with "now"; overwrite it before saving so
        // the member appears to have been around since their planned date.
        $user->joined_at = Dates::toUtc($joinedAt);
        $user->is_email_confirmed = true;

        $user->save();

        return $user;
    }

    protected function uniqueUsername(string $candidate): string
    {
        $username = preg_replace('/[^A-Za-z0-9_-]/', '', $candidate) ?? '';
        $username = trim($username, '-_');

        if (strlen($username) < 3) {
            $username = 'member'.Str::lower(Str::random(6));
        }

        $username = substr($username, 0, 25);
        $base = $username;
        $suffix = 1;

        while (User::where('username', $username)->exists()) {
            $suffix++;
            $username = substr($base, 0, 25 - strlen((string) $suffix)).$suffix;

            if ($suffix > 500) {
                $username = 'member'.Str::lower(Str::random(10));
                break;
            }
        }

        return $username;
    }

    protected function uniqueEmail(string $username): string
    {
        $domain = $this->settings->emailDomain();
        $local = strtolower(preg_replace('/[^a-z0-9._-]/i', '', $username) ?? 'member');
        $email = $local.'@'.$domain;
        $suffix = 1;

        while (User::where('email', $email)->exists()) {
            $suffix++;
            $email = $local.'+'.$suffix.'@'.$domain;

            if ($suffix > 500) {
                $email = $local.'+'.Str::lower(Str::random(8)).'@'.$domain;
                break;
            }
        }

        return $email;
    }
}
