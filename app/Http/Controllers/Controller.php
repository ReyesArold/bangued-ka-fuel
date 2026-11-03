<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

/**
 * Base controller.
 *
 * AuthorizesRequests is what makes $this->authorize() and Gate::authorize()
 * usable from the action methods, which is how AdminApiController enforces
 * StationPolicy. Without it every authorize() call is an undefined method.
 *
 * The trait's default denial is also a safe default for this app: an ability a
 * policy does not define is refused rather than allowed.
 */
abstract class Controller
{
    use AuthorizesRequests, ValidatesRequests;
}
