<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Installation extends Model
{
    use HasFactory;

    /** @var array<int, string> */
    protected $fillable = [
        'name',
        'path',
        'hidden',
        'status',
        'progress',
        'current_step',
        'output',
        'last_updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hidden' => 'boolean',
            'last_updated_at' => 'datetime',
        ];
    }
}
