<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Intake extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'intake_type',
        'reporter_name',
        'contact_number',
        'location',
        'animal_name',
        'species',
        'breed',
        'estimated_age',
        'gender',
        'description',
        'status',
        'assessment_notes',
        'assessed_by',
        'assessment_date',
        'converted_animal_id',
    ];

    public function documents()
    {
        return $this->hasMany(IntakeDocument::class, 'intake_id');
    }
}
