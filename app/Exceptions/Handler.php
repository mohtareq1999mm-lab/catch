<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Marvel\Exceptions\MarvelException;
use Illuminate\Support\Facades\Lang;
use Spatie\Permission\Exceptions\GuardDoesNotMatch;
use Spatie\Permission\Exceptions\PermissionAlreadyExists;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Exceptions\RoleAlreadyExists;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Exceptions\UnauthorizedException as SpatieUnauthorizedException;
use Spatie\Permission\Exceptions\WildcardPermissionInvalidArgument;
use Spatie\Permission\Exceptions\WildcardPermissionNotImplementsContract;
use Spatie\Permission\Exceptions\WildcardPermissionNotProperlyFormatted;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Throwable;
use Illuminate\Http\Response;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $exception
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function render($request, Throwable $exception)
    {
        // Return JSON for API requests
        if ($this->shouldReturnJson($request, $exception)) {
            return $this->handleJsonResponse($request, $exception);
        }

        return parent::render($request, $exception);
    }

    /**
     * Determine if the exception should return JSON.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $exception
     * @return bool
     */
    protected function shouldReturnJson($request, Throwable $exception): bool
    {
        return $request->expectsJson() ||
            str_contains($request->getPathInfo(), '/api') ||
            $request->is('api/*');
    }

    /**
     * Handle JSON exception responses.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $exception
     * @return \Illuminate\Http\JsonResponse
     */
    private function handleJsonResponse($request, Throwable $exception)
    {
        $statusCode = 500;
        $message = 'Internal Server Error';
        $errors = [];

        // Handle ModelNotFoundException
        if ($exception instanceof ModelNotFoundException) {
            $statusCode = 404;
            $message = 'Resource Not Found';
        }
        // Handle NotFoundHttpException
        elseif ($exception instanceof NotFoundHttpException) {
            $statusCode = 404;
            $message = $exception->getMessage() ?: 'Not Found';
        }
        // Handle MethodNotAllowedHttpException
        elseif ($exception instanceof MethodNotAllowedHttpException) {
            $statusCode = 405;
            $message = 'Method Not Allowed';
        }
        // Handle invalid/expired/missing signed-URL signatures (customer view/download)
        elseif ($exception instanceof InvalidSignatureException) {
            $statusCode = 403;
            $message = 'Invalid signature';
        }
        // Handle Spatie permission exceptions
        elseif ($exception instanceof SpatieUnauthorizedException) {
            if ($exception->getMessage() === 'User is not logged in.') {
                $statusCode = 401;
                $message = $this->messageForSpatieUnauthorized($exception);
            } else {
                $statusCode = 403;
                $message = $this->messageForSpatieUnauthorized($exception);
            }
        } elseif ($exception instanceof PermissionDoesNotExist) {
            $statusCode = 404;
            $message = $this->messageForPermissionDoesNotExist($exception);
        } elseif ($exception instanceof RoleDoesNotExist) {
            $statusCode = 404;
            $message = $this->messageForRoleDoesNotExist($exception);
        } elseif ($exception instanceof PermissionAlreadyExists) {
            $statusCode = 409;
            $message = $this->messageForPermissionAlreadyExists($exception);
        } elseif ($exception instanceof RoleAlreadyExists) {
            $statusCode = 409;
            $message = $this->messageForRoleAlreadyExists($exception);
        } elseif ($exception instanceof GuardDoesNotMatch) {
            $statusCode = 400;
            $message = $this->messageForGuardDoesNotMatch($exception);
        } elseif ($exception instanceof WildcardPermissionInvalidArgument) {
            $statusCode = 400;
            $message = $this->translateNotice(WILDCARD_PERMISSION_INVALID_ARGUMENT);
        } elseif ($exception instanceof WildcardPermissionNotImplementsContract) {
            $statusCode = 400;
            $message = $this->translateNotice(WILDCARD_PERMISSION_NOT_IMPLEMENTS_CONTRACT);
        } elseif ($exception instanceof WildcardPermissionNotProperlyFormatted) {
            $statusCode = 400;
            $message = $this->messageForWildcardPermissionNotProperlyFormatted($exception);
        }
        // Handle AuthenticationException (unauthenticated)
        elseif ($exception instanceof AuthenticationException) {
            $statusCode = 401;
            $message = 'Unauthenticated';
        }
        // Handle AuthorizationException
        elseif ($exception instanceof AuthorizationException) {
            $statusCode = 403;
            $message =  'This action is unauthorized';
        }
        // Handle ValidationException
        elseif ($exception instanceof ValidationException) {
            $statusCode = 422;
            $message = $exception->getMessage();
            $errors = $exception->validator->errors()->toArray();
        }
        // Handle HttpException (includes various HTTP status codes)
        elseif ($exception instanceof HttpException) {
            $statusCode = $exception->getStatusCode();
            $message = $exception->getMessage() ?: 'HTTP Exception';
        }
        // Handle QueryException (SQL errors)
        elseif ($exception instanceof QueryException) {
            $statusCode = 409;
            $message = 'Database error occurred. Please check your request and try again.';
            if (app()->environment('local')) {
                $message .= ' ' . $exception->getMessage();
            }
        }
        // Handle MarvelException
        elseif ($exception instanceof MarvelException) {
            $exceptionMessage = $exception->getMessage();
            if (str_contains($exceptionMessage, 'NOT_FOUND')) {
                $statusCode = 404;
            } elseif (str_contains($exceptionMessage, 'NOT_AUTHORIZED')) {
                $statusCode = 403;
            } else {
                $statusCode = 500;
            }
            $message = $this->translateNotice($exceptionMessage);
        }
        // Handle other exceptions
        else {
            $statusCode = 500;
            $message = config('app.debug') ? $exception->getMessage() : 'Internal Server Error';
        }

        $responseData = [
            'message' => $message,
            'status' => false,
        ];

        if (!empty($errors)) {
            $responseData['errors'] = $errors;
        }

        return response()->json($responseData, $statusCode);
    }

    private function translateNotice(string $key, array $replace = []): string
    {
        $normalizedKey = $this->stripNoticeDomain($key);
        $messageKey = 'message.' . $normalizedKey;

        if (Lang::has($messageKey)) {
            return __($messageKey, $replace);
        }

        $translated = __($normalizedKey, $replace);

        if ($translated === $normalizedKey && $normalizedKey !== $key) {
            $translated = __($key, $replace);
        }

        return $translated;
    }

    private function stripNoticeDomain(string $key): string
    {
        $prefix = config('shop.app_notice_domain');

        if ($prefix && str_starts_with($key, $prefix)) {
            return substr($key, strlen($prefix));
        }

        return $key;
    }

    private function messageForSpatieUnauthorized(SpatieUnauthorizedException $exception): string
    {
        $message = $exception->getMessage();

        if ($message === 'User is not logged in.') {
            return $this->translateNotice(USER_NOT_LOGGED_IN);
        }

        if (str_starts_with($message, 'Authorizable class')) {
            $class = null;

            if (preg_match('/Authorizable class `(.+)`/', $message, $matches)) {
                $class = $matches[1] ?? null;
            }

            return $this->translateNotice(PERMISSION_MISSING_TRAIT_HAS_ROLES, ['class' => $class ?? '']);
        }

        $roles = $exception->getRequiredRoles();
        $permissions = $exception->getRequiredPermissions();

        if (!empty($roles)) {
            $translated = $this->translateNotice(PERMISSION_MISSING_ROLES);

            if (config('permission.display_role_in_exception')) {
                $translated .= ' ' . $this->translateNotice(PERMISSION_NECESSARY_ROLES, [
                    'roles' => implode(', ', $roles),
                ]);
            }

            return $translated;
        }

        if (!empty($permissions)) {
            if (str_contains($message, 'any of the necessary access rights')) {
                $translated = $this->translateNotice(PERMISSION_MISSING_ROLES_OR_PERMISSIONS);

                if (config('permission.display_permission_in_exception') && config('permission.display_role_in_exception')) {
                    $translated .= ' ' . $this->translateNotice(PERMISSION_NECESSARY_ROLES_OR_PERMISSIONS, [
                        'roles_or_permissions' => implode(', ', $permissions),
                    ]);
                }

                return $translated;
            }

            $translated = $this->translateNotice(PERMISSION_MISSING_PERMISSIONS);

            if (config('permission.display_permission_in_exception')) {
                $translated .= ' ' . $this->translateNotice(PERMISSION_NECESSARY_PERMISSIONS, [
                    'permissions' => implode(', ', $permissions),
                ]);
            }

            return $translated;
        }

        return $this->translateNotice(PERMISSION_MISSING_PERMISSIONS);
    }

    private function messageForPermissionDoesNotExist(PermissionDoesNotExist $exception): string
    {
        $message = $exception->getMessage();

        if (preg_match('/permission named `(.+)` for guard `(.+)`/', $message, $matches)) {
            return $this->translateNotice(PERMISSION_NOT_FOUND_BY_NAME, [
                'permission' => $matches[1],
                'guard' => $matches[2],
            ]);
        }

        if (preg_match('/\[permission\] with ID `(.+)` for guard `(.+)`/', $message, $matches)) {
            return $this->translateNotice(PERMISSION_NOT_FOUND_BY_ID, [
                'id' => $matches[1],
                'guard' => $matches[2],
            ]);
        }

        return $message;
    }

    private function messageForRoleDoesNotExist(RoleDoesNotExist $exception): string
    {
        $message = $exception->getMessage();

        if (preg_match('/role named `(.+)` for guard `(.+)`/', $message, $matches)) {
            return $this->translateNotice(ROLE_NOT_FOUND_BY_NAME, [
                'role' => $matches[1],
                'guard' => $matches[2],
            ]);
        }

        if (preg_match('/role with ID `(.+)` for guard `(.+)`/', $message, $matches)) {
            return $this->translateNotice(ROLE_NOT_FOUND_BY_ID, [
                'id' => $matches[1],
                'guard' => $matches[2],
            ]);
        }

        return $message;
    }

    private function messageForPermissionAlreadyExists(PermissionAlreadyExists $exception): string
    {
        $message = $exception->getMessage();

        if (preg_match('/A `(.+)` permission already exists for guard `(.+)`\./', $message, $matches)) {
            return $this->translateNotice(PERMISSION_ALREADY_EXISTS, [
                'permission' => $matches[1],
                'guard' => $matches[2],
            ]);
        }

        return $message;
    }

    private function messageForRoleAlreadyExists(RoleAlreadyExists $exception): string
    {
        $message = $exception->getMessage();

        if (preg_match('/A role `(.+)` already exists for guard `(.+)`\./', $message, $matches)) {
            return $this->translateNotice(ROLE_ALREADY_EXISTS, [
                'role' => $matches[1],
                'guard' => $matches[2],
            ]);
        }

        return $message;
    }

    private function messageForGuardDoesNotMatch(GuardDoesNotMatch $exception): string
    {
        $message = $exception->getMessage();

        if (preg_match('/guard `(.+)` instead of `(.+)`/', $message, $matches)) {
            return $this->translateNotice(GUARD_DOES_NOT_MATCH, [
                'expected' => $matches[1],
                'given' => $matches[2],
            ]);
        }

        return $message;
    }

    private function messageForWildcardPermissionNotProperlyFormatted(
        WildcardPermissionNotProperlyFormatted $exception
    ): string {
        $message = $exception->getMessage();

        if (preg_match('/Wildcard permission `(.+)` is not properly formatted\./', $message, $matches)) {
            return $this->translateNotice(WILDCARD_PERMISSION_NOT_PROPERLY_FORMATTED, [
                'permission' => $matches[1],
            ]);
        }

        return $message;
    }
}