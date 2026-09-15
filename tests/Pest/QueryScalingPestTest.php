<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\ExpectationFailedException;

beforeEach(function (): void {
    $schema = DB::connection()->getSchemaBuilder();
    $schema->create('posts', static function (Blueprint $table): void {
        $table->increments('id');
        $table->string('title');
    });
});

function pestPopulate(int $scale): void
{
    for ($i = 1; $i <= $scale; $i++) {
        DB::table('posts')->insert(['title' => "post-{$i}"]);
    }
}

test('pest scenario passes when the query count stays constant', function (): void {
    $this->assertQueriesScaleConstantly(
        scales: [2, 5, 10],
        populate: fn (int $scale) => pestPopulate($scale),
        run: function (): void {
            DB::table('posts')->count();
        },
    );
});

test('pest scenario fails on growing query counts', function (): void {
    $this->assertQueriesScaleConstantly(
        scales: [2, 5, 10],
        populate: fn (int $scale) => pestPopulate($scale),
        run: function (): void {
            $posts = DB::table('posts')->get();
            foreach ($posts as $post) {
                DB::table('posts')->where('id', $post->id)->first();
            }
        },
    );
})->throws(ExpectationFailedException::class);
