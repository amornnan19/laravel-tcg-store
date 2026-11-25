<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;

/**
 * Placeholder for storefront pages that M3 builds. The routes exist so links can use route().
 */
class NotBuiltYet extends Controller
{
    public function __invoke(): never
    {
        abort(404);
    }
}
