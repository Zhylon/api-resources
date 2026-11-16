<?php

declare(strict_types=1);

namespace Zhylon\ApiResources\Http\Errors;

use Illuminate\Http\Request;

/**
 * Implement on your own exceptions to control how `ApiExceptionRenderer` renders them.
 */
interface ProvidesApiErrors
{
    /**
     * @return ApiError|ApiErrorResponse|list<ApiError>
     */
    public function toApiErrors(Request $request): ApiError|ApiErrorResponse|array;
}
