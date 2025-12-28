<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

     /*=============================================
    =            Relationships                    =
    =============================================*/

    /**
     * Get all bundles owned by the user
     */
    public function bundles()
    {
        return $this->hasMany(Bundle::class);
    }

    /**
     * Get all highlights created by the user
     */
    public function highlights()
    {
        return $this->hasMany(Highlight::class);
    }

    /**
     * Get all comments created by the user
     */
    public function comments()
    {
        return $this->hasMany(Comment::class);
    }
}