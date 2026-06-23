<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    // Settings whose value is a stored file key rather than plain text. These are
    // exposed as absolute URLs so the frontend can render them directly regardless
    // of which filesystem disk is configured (local vs. S3/R2).
    private const IMAGE_KEYS = ['logo_path', 'banner_image_path'];

    public static function getAll(): array
    {
        $values = static::query()->pluck('value', 'key')->toArray();

        foreach (self::IMAGE_KEYS as $key) {
            if (!empty($values[$key])) {
                $values[$key] = Storage::url($values[$key]);
            }
        }

        return $values;
    }

    public static function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            static::updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
