<?php

namespace App\Models;

use App\Models\Traits\FilterQuery;
use EloquentFilter\Filterable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractModel extends Model
{
    use Filterable, FilterQuery, HasFactory;
}
