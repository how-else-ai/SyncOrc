<?php

namespace Tests\Unit;

use App\Casts\VectorClockCast;
use App\Models\SyncState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VectorClockCastTest extends TestCase
{
    use RefreshDatabase;

    public function test_cast_get_and_set(): void
    {
        $cast = new VectorClockCast;
        $model = new SyncState;

        $this->assertSame([], $cast->get($model, 'vector_clock', null, []));
        $this->assertSame(['a' => 1], $cast->get($model, 'vector_clock', json_encode(['a' => 1]), []));

        $this->assertNull($cast->set($model, 'vector_clock', null, []));
        $this->assertSame(json_encode(['a' => 2]), $cast->set($model, 'vector_clock', ['a' => '2'], []));
        $this->assertSame(json_encode(['b' => 3]), $cast->set($model, 'vector_clock', json_encode(['b' => 3]), []));
    }
}
