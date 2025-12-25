<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bundle extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'case_number',
        'total_documents',
        'status',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'metadata' => 'array',
        'total_documents' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the user that owns the bundle.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the documents for the bundle.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class)->orderBy('order');
    }

    /**
     * Update the total documents count.
     */
    public function updateDocumentCount(): void
    {
        $this->total_documents = $this->documents()->count();
        $this->save();
    }

    /**
     * Soft delete the bundle and its documents.
     */
    public function softDelete(): void
    {
        $this->documents()->delete();
        $this->delete();
    }
}