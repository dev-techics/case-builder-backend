<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    protected $fillable = [
        'bundle_id',
        'document_id',
        'user_id',
        'page_number',
        'text',
        'selected_text',
        'x',
        'y',
        'page_y',
        'resolved',
    ];

    protected $casts = [
        'x' => 'float',
        'y' => 'float',
        'page_y' => 'float',
        'page_number' => 'integer',
        'resolved' => 'boolean',
    ];

    /*=============================================
    =            Relationships                    =
    =============================================*/

    /**
     * Get the bundle this comment belongs to
     */
    public function bundle()
    {
        return $this->belongsTo(Bundle::class);
    }

    /**
     * Get the document this comment belongs to
     */
    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Get the user who created this comment
     */
    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    /*=============================================
    =            Scopes                           =
    =============================================*/

    /**
     * Scope comments by bundle
     */
    public function scopeForBundle($query, $bundleId)
    {
        return $query->where('bundle_id', $bundleId);
    }

    /**
     * Scope comments by document
     */
    public function scopeForDocument($query, $documentId)
    {
        return $query->where('document_id', $documentId);
    }

    /**
     * Scope comments by page number
     */
    public function scopeOnPage($query, $pageNumber)
    {
        return $query->where('page_number', $pageNumber);
    }

    /**
     * Scope comments by user
     */
    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope unresolved comments
     */
    public function scopeUnresolved($query)
    {
        return $query->where('resolved', false);
    }

    /**
     * Scope resolved comments
     */
    public function scopeResolved($query)
    {
        return $query->where('resolved', true);
    }

    /*=============================================
    =            Helper Methods                   =
    =============================================*/

    /**
     * Get position as array
     */
    public function getPositionAttribute()
    {
        return [
            'x' => $this->x,
            'y' => $this->y,
            'page_y' => $this->page_y,
        ];
    }

    /**
     * Mark comment as resolved
     */
    public function resolve()
    {
        $this->update(['resolved' => true]);
    }

    /**
     * Mark comment as unresolved
     */
    public function unresolve()
    {
        $this->update(['resolved' => false]);
    }

    /**
     * Toggle resolved status
     */
    public function toggleResolved()
    {
        $this->update(['resolved' => !$this->resolved]);
    }

    
}