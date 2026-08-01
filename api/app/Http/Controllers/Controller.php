<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PaginatesLists;

abstract class Controller
{
    use PaginatesLists;
}
