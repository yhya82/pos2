<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModuleSetting extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'module_name',
        'is_enabled',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * Whether a configurable module (purchase_management,
     * return_management, customer_credit, notifications) is currently
     * turned on — the sidebar hides nav items for disabled modules per
     * SRS Sec. 20.3.
     */
    public static function enabled(string $moduleName): bool
    {
        return static::where('module_name', $moduleName)->value('is_enabled') ?? false;
    }
}
