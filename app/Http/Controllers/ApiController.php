<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

abstract class ApiController extends Controller
{
    use AuthorizesRequests, ValidatesRequests;

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user() ?? Auth::user();

        if (! $user) {
            throw ApiException::unauthorized();
        }

        return $user;
    }

    protected function ok(mixed $data = null, string $message = 'OK', array $meta = []): JsonResponse
    {
        $payload = ['success' => true, 'message' => $message, 'data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload);
    }

    protected function created(mixed $data = null, string $message = 'Created'): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], 201);
    }

    protected function fail(string $message, int $code = 400, mixed $errors = null): JsonResponse
    {
        $payload = ['success' => false, 'message' => $message];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $code);
    }

    protected function paginated(LengthAwarePaginator $paginator, string $message = 'OK'): JsonResponse
    {
        return $this->ok($paginator->items(), $message, [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }

    protected function authorizeCompany(int $companyId): void
    {
        $user = $this->user();

        if (! $user->belongsToCompany($companyId)) {
            throw ApiException::forbidden('You are not authorized for this company');
        }
    }

    protected function authorizeMovement(Model $movement): void
    {
        $this->authorizeCompany((int) $movement->getAttribute('company_id'));
    }

    protected function audit(string $action, ?Model $subject = null, ?array $old = null, ?array $new = null, ?int $companyId = null): void
    {
        app(\App\Services\AuditLogger::class)->log($action, $subject, $old, $new, $companyId);
    }
}
