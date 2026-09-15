<?php

declare(strict_types=1);

namespace Ralder\QueryScaling\Tests\Integration\Fixtures;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read int $id
 * @property-read Collection<int, Comment> $comments
 */
final class Post extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'posts';

    protected $connection = 'testing';

    /**
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }
}
