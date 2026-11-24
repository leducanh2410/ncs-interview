<?php

use App\Enums\ErrorCode;
use App\Helpers\RequestHelper;
use App\Helpers\ResponseHelper;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: '/api'
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request) {
            return $request->wantsJson();
        });
        $exceptions->render(function (InvalidSignatureException $e) {
            $frontendUrl = config('app.front_url').'/auth/login?error=link_expired';

            return redirect($frontendUrl);
        });
        $exceptions->render(function (BadRequestException $e) {
            return ResponseHelper::badRequest();
        });
        $exceptions->render(function (AuthenticationException $e) {
            if (RequestHelper::getTokenFromRequest(request()) !== null) {
                return ResponseHelper::unauthorized(ErrorCode::AuthInvalidToken);
            }

            return ResponseHelper::unauthorized();
        });
        $exceptions->render(function (NotFoundHttpException|ModelNotFoundException $e) {
            return ResponseHelper::notFound();
        });
        $exceptions->render(function (MethodNotAllowedHttpException $e) {
            return ResponseHelper::methodNotAllowed();
        });
        $exceptions->render(function (AccessDeniedHttpException|AuthorizationException $e) {
            return ResponseHelper::forbidden();
        });
        $exceptions->render(function (ValidationException $e) {
            return ResponseHelper::unprocessableEntity(ErrorCode::UnprocessableEntity, $e->errors());
        });
        $exceptions->render(function (TooManyRequestsHttpException $e) {
            return ResponseHelper::badRequest(ErrorCode::Throttle);
        });
        $exceptions->render(function (Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return match ($e->getStatusCode()) {
                403 => ResponseHelper::forbidden(),
                404 => ResponseHelper::notFound(),
                405 => ResponseHelper::methodNotAllowed(),
                429 => ResponseHelper::badRequest(ErrorCode::Throttle),
                default => ResponseHelper::internalServerError(),
            };
        });
        $exceptions->render(function (Throwable $e) {
            return ResponseHelper::internalServerError();
        });
    })->create();
