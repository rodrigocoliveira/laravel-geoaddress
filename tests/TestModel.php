<?php

namespace Multek\LaravelGeoaddress\Tests;

use Illuminate\Database\Eloquent\Model;
use Multek\LaravelGeoaddress\Traits\Addressable;

/**
 * Test model for address testing.
 */
class TestModel extends Model
{
    use Addressable;

    protected $fillable = ['name'];
}
