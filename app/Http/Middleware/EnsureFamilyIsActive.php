<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Family;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFamilyIsActive
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $family = app()->bound(Family::class) ? app(Family::class) : null;

        if ($family instanceof Family && $family->isArchived()) {
            abort_unless(in_array($request->route()?->getName(), [
                'family.archive.show',
                'family.archive.restore',
                'family.archive.export',
            ], true), 423, 'This family is archived. Only restore and export are available.');
        }

        return $next($request);
    }
}
