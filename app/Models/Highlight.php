<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Highlight extends Model
{
    protected $fillable = [
        'bundle_id',
        'document_id',
        'user_id',
        'page_number',
        'x',
        'y',
        'width',
        'height',
        'text',
        'color_name',
        'color_hex',
        'color_rgb',
        'opacity',
    ];

    protected $casts = [
        'color_rgb' => 'array',
        'x' => 'float',
        'y' => 'float',
        'width' => 'float',
        'height' => 'float',
        'opacity' => 'float',
        'page_number' => 'integer',
    ];

    /*=============================================
    =            Relationships                    =
    =============================================*/

    /**
     * Get the bundle this highlight belongs to
     */
    public function bundle()
    {
        return $this->belongsTo(Bundle::class);
    }

    /**
     * Get the document this highlight belongs to
     */
    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Get the user who created this highlight
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /*=============================================
    =            Scopes                           =
    =============================================*/

    /**
     * Scope highlights by bundle
     */
    public function scopeForBundle($query, $bundleId)
    {
        return $query->where('bundle_id', $bundleId);
    }

    /**
     * Scope highlights by document
     */
    public function scopeForDocument($query, $documentId)
    {
        return $query->where('document_id', $documentId);
    }

    /**
     * Scope highlights by page number
     */
    public function scopeOnPage($query, $pageNumber)
    {
        return $query->where('page_number', $pageNumber);
    }

    /**
     * Scope highlights by user
     */
    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope highlights by color
     */
    public function scopeByColor($query, $colorName)
    {
        return $query->where('color_name', $colorName);
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
            'width' => $this->width,
            'height' => $this->height,
        ];
    }

    /**
     * Get color as full object
     */
    public function getColorAttribute()
    {
        return [
            'name' => $this->color_name,
            'hex' => $this->color_hex,
            'rgb' => $this->color_rgb,
        ];
    }
}