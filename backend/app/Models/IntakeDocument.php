<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntakeDocument extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'intake_id',
        'file_path',
        'original_name',
    ];

    public function intake()
    {
        return $this->belongsTo(Intake::class, 'intake_id');
    }
}
