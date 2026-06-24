<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * @property string $telegram_id
 * @property string $categoria
 * @property float $limite
 */
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
