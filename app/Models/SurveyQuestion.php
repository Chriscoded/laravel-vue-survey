<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Survey;

class SurveyQuestion extends Model
{
    use HasFactory;

    // protected $table = 'survey_questions';

    protected $fillable = ['question', 'data', 'type', 'survey_id', 'description'];

    public function survey(){
        return $this->belongsTo(Survey::class);
    }
}

