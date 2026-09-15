<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Tests\Integration\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class Comment extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'comments';

    protected $connection = 'testing';
}
