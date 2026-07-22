<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HardwareSetting extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'barcode_scanner_enabled',
        'auto_print_receipt',
        'default_printer_name',
        'paper_size',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'barcode_scanner_enabled' => 'boolean',
            'auto_print_receipt' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return static::find(1);
    }
}
