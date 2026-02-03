<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CoverPage extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'type',
        'template_key',
        'html',
        'lexical_json',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    /**
     * Get the user that owns the cover page
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get bundles using this as front cover
     */
    public function bundlesAsFrontCover(): HasMany
    {
        return $this->hasMany(Bundle::class, 'front_cover_page_id');
    }

    /**
     * Get bundles using this as back cover
     */
    public function bundlesAsBackCover(): HasMany
    {
        return $this->hasMany(Bundle::class, 'back_cover_page_id');
    }

    /**
     * Check if cover is in use
     */
    public function isInUse(): bool
    {
        return $this->bundlesAsFrontCover()->exists() 
            || $this->bundlesAsBackCover()->exists();
    }

    /**
     * Scope to get only front pages
     */
    public function scopeFront($query)
    {
        return $query->where('type', 'front');
    }

    /**
     * Scope to get only back pages
     */
    public function scopeBack($query)
    {
        return $query->where('type', 'back');
    }

    /**
     * Scope to get default cover pages
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Set this as default and unset others
     */
    public function setAsDefault(): void
    {
        // Unset other defaults for this user and type
        static::where('user_id', $this->user_id)
            ->where('type', $this->type)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->update(['is_default' => true]);
    }
}
