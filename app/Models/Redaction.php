<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Redaction extends Model
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
        'name',
        'fill_hex',
        'opacity',
        'border_hex',
        'border_width',
    ];

    protected $casts = [
        'x' => 'float',
        'y' => 'float',
        'width' => 'float',
        'height' => 'float',
        'opacity' => 'float',
        'border_width' => 'float',
        'page_number' => 'integer',
    ];

    /*=============================================
    =            Relationships                    =
    =============================================*/

    /**
     * Get the bundle this redaction belongs to
     */
    public function bundle()
    {
        return $this->belongsTo(Bundle::class);
    }

    /**
     * Get the document this redaction belongs to
     */
    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Get the user who created this redaction
     */
    public function user()
    {
        return $this->belongsTo(User::class);
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
     * Get style as array
     */
    public function getStyleAttribute()
    {
        return [
            'name' => $this->name,
            'fill_hex' => $this->fill_hex,
            'opacity' => $this->opacity,
            'border_hex' => $this->border_hex,
            'border_width' => $this->border_width,
        ];
    }
}
