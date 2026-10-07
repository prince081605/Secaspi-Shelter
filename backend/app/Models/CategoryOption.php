<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry in an admin-managed list (a "category"): a dog breed, a behavioral issue, a medical
 * record type, a valid ID type. Admins add, rename, hide and restore them on the Categories page
 * (CategoryOptionController); everything else reads them through the helpers below.
 */
class CategoryOption extends Model
{
    /**
     * The lists. `keyed` lists give each option a fixed key that records store, so renaming
     * only changes the label people see. The others store the words themselves on the record
     * (an animal's breed, its behavioral issues), so renaming rewrites the records that use it.
     * `key_max` is the length of the column the key is stored in.
     */
    public const TYPES = [
        'dog_breed' => ['name' => 'Dog breeds', 'keyed' => false, 'species' => 'dog'],
        'cat_breed' => ['name' => 'Cat breeds', 'keyed' => false, 'species' => 'cat'],
        'behavioral_issue' => ['name' => 'Behavioral issues', 'keyed' => false],
        'medical_record_type' => ['name' => 'Medical record types', 'keyed' => true, 'key_max' => 20],
        'valid_id_type' => ['name' => 'Valid ID types', 'keyed' => true, 'key_max' => 50],
    ];

    protected $fillable = ['type', 'value', 'label', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type)->orderBy('sort_order')->orderBy('id');
    }

    /** The values offered for new entries (hidden ones left out), in list order. */
    public static function activeValues(string $type): array
    {
        return self::ofType($type)->where('is_active', true)->pluck('value')->all();
    }

    /** value => label for the whole list, hidden ones included, so older records still display. */
    public static function labels(string $type): array
    {
        return self::ofType($type)->pluck('label', 'value')->all();
    }

    /** The label for a stored value, or a tidied-up value when it isn't on the list. */
    public static function labelFor(string $type, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return self::labels($type)[$value] ?? ucfirst(str_replace('_', ' ', $value));
    }
}
