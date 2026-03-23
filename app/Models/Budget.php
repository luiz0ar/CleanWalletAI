<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Budget extends Model
{
    protected $connection = 'mongodb'; 
    
    protected $collection = 'budgets'; 

    protected $fillable = [
        'telegram_id',
        'categoria',
        'limite',
    ];
}
