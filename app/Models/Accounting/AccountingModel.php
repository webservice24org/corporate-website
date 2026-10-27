<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

abstract class AccountingModel extends Model
{
    protected $guarded = ['id'];
}
