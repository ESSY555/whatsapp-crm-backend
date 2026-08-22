<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Traits\ApiResponse;

abstract class ApiController extends Controller
{
    use ApiResponse;
}
