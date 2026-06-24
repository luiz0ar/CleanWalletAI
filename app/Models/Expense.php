<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * @property string $telegram_id
 * @property float $valor
 * @property string $categoria
 * @property string $descricao
 * @property string $data
 * @property string $tipo
 * @property int|null $parcelas
 * @property float|null $valor_total
 * @property float|null $valor_parcela
 * @property string|null $group_id
 */
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
