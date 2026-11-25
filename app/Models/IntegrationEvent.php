<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['external_id', 'topic', 'digest', 'status', 'reason'])]
#[Hidden(['digest'])]
class IntegrationEvent extends Model {}
