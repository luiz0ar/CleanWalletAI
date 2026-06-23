<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Expense extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'expenses';

    protected $fillable = [
        'telegram_id',
        'valor',
        'categoria',
        'descricao',
        'data',
        'tipo',
        'parcelas',
        'valor_total',
        'valor_parcela',
        'group_id',
    ];
}
