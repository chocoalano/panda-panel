<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users\Imports;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PandaPanel\Actions\Imports\ImportColumn;
use PandaPanel\Actions\Imports\Importer;

/**
 * Loads users from a spreadsheet.
 *
 * Matched on the email address, so re-uploading a corrected file updates the
 * accounts it describes rather than creating second copies of them. That is
 * what makes a failure report worth downloading: fix the four rows it names,
 * upload the same file again, and the other rows are updated in place.
 *
 * No password column. A password that arrived in a spreadsheet is a password
 * that was in a spreadsheet — new accounts get a random one and go through
 * the reset flow like anybody else.
 */
final class UserImporter extends Importer
{
    /**
     * @return class-string<Model>
     */
    public static function model(): string
    {
        return User::class;
    }

    /**
     * @return list<ImportColumn>
     */
    public static function columns(): array
    {
        return [
            ImportColumn::make('name')
                ->guess(['full name', 'user'])
                ->required()
                ->rules(['string', 'max:255']),
            ImportColumn::make('email')
                ->label('Email')
                ->guess(['e-mail', 'email address', 'e-mail address'])
                ->required()
                ->rules(['email', 'max:255'])
                ->castUsing(static fn (string $value): string => mb_strtolower(trim($value))),
            ImportColumn::make('is_admin')
                ->label('Administrator')
                ->guess(['admin', 'is admin', 'role'])
                ->castUsing(static fn (string $value): bool => in_array(
                    mb_strtolower($value),
                    ['1', 'yes', 'y', 'true', 'admin', 'administrator'],
                    true,
                )),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function resolve(array $data): ?Model
    {
        $email = $data['email'] ?? null;

        if (! is_string($email) || $email === '') {
            return null;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            return $user;
        }

        $user = new User;

        // Only for a new account: an existing one keeps the password it has,
        // which a re-upload must not reset.
        $user->forceFill([
            'password' => Hash::make(Str::random(32)),
            'email_verified_at' => null,
        ]);

        return $user;
    }
}
