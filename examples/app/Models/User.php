<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use PandaPanel\Contracts\PanelNotifiable;
use PandaPanel\Contracts\PanelUser;
use PandaPanel\Core\Panel;

/**
 * The user model the example panels are written against.
 *
 * Part of the package's examples rather than its source: a panel framework has
 * no business owning the application's user model. It is here so the examples
 * compile and the test suite has something to sign in as, and it shows the
 * three things a panel actually asks of a user model — `Notifiable` for the
 * notification centre, `TwoFactorAuthenticatable` for the security page, and
 * `PanelUser` for a rule about the account rather than about the panel.
 *
 * @property bool $is_admin
 */
class User extends Authenticatable implements MustVerifyEmail, PanelNotifiable, PanelUser, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;
    use PasskeyAuthenticatable;
    use TwoFactorAuthenticatable;

    /**
     * `is_admin` is deliberately absent.
     *
     * Registration and profile updates both fill from request input, and a
     * privilege flag that is mass-assignable is a privilege anyone can grant
     * themselves by adding a field to a form post. Promoting an account is an
     * explicit `forceFill` or a dedicated action, never a side effect of
     * saving a form.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * A rule about the account, asked on every panel request alongside the
     * panel's own predicate. Both must agree.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasVerifiedEmail();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_email_confirmed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
