<?php

namespace App\Services;

use App\Models\ErrorLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Mencatat galat aplikasi ke tabel `error_logs` dengan kode referensi per portal
 * (ER-ADM-001, ER-GR-001, ER-PGW-001, ER-SSW-001, ER-SUP-001, ER-PUB-001, ER-SYS-001).
 */
class ErrorLogger
{
    public const ATTRIBUTE_CODE = '_error_log_code';

    /** @var array<int, string> */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'sandi',
        'token',
        '_token',
        'secret',
        'api_key',
        'code',
        'wali_code',
    ];

    public static function catat(Throwable $e, ?Request $request = null, ?int $statusCode = null): ?string
    {
        if (self::diabaikan($e)) {
            return null;
        }

        if ($request !== null) {
            $sudahAda = $request->attributes->get(self::ATTRIBUTE_CODE);
            if (is_string($sudahAda) && $sudahAda !== '') {
                return $sudahAda;
            }
        }

        try {
            $portal = self::deteksiPortal($request);
            $status = $statusCode ?? self::deteksiStatus($e);
            $file = self::ringkasPath($e->getFile());
            $line = $e->getLine();
            $routeName = $request?->route()?->getName();
            $exceptionClass = Str::limit(get_class($e), 190, '');
            $fingerprint = sha1(implode('|', [
                $portal,
                (string) $status,
                $exceptionClass,
                $file ?? '',
                (string) $line,
                $routeName ?? '',
            ]));

            $existing = ErrorLog::query()
                ->where('fingerprint', $fingerprint)
                ->whereNull('resolved_at')
                ->orderByDesc('id')
                ->first();

            if ($existing !== null) {
                $existing->forceFill([
                    'occurrences' => $existing->occurrences + 1,
                    'last_seen_at' => now(),
                    'message' => Str::limit($e->getMessage() ?: $exceptionClass, 2000),
                ])->save();

                if ($request !== null) {
                    $request->attributes->set(self::ATTRIBUTE_CODE, $existing->code);
                }

                return $existing->code;
            }

            $user = $request?->user();
            $schoolId = $user?->school_id ?? ($request?->hasSession() ? $request->session()->get('school_id') : null);

            $record = self::simpanBaru([
                'portal' => $portal,
                'fingerprint' => $fingerprint,
                'status_code' => $status,
                'exception_class' => $exceptionClass,
                'message' => Str::limit($e->getMessage() ?: $exceptionClass, 2000),
                'file' => $file,
                'line' => $line,
                'trace' => self::ringkasTrace($e),
                'url' => $request ? Str::limit($request->fullUrl(), 500) : null,
                'method' => $request ? Str::limit($request->method(), 10, '') : null,
                'route_name' => $routeName ? Str::limit($routeName, 120, '') : null,
                'school_id' => is_numeric($schoolId) ? (int) $schoolId : null,
                'user_id' => $user?->id,
                'user_role' => $user?->role?->value ?? (is_string($user?->role) ? $user->role : null),
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent() ? Str::limit((string) $request->userAgent(), 255) : null,
                'context' => self::bangunKonteks($request),
                'occurrences' => 1,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);

            if ($record !== null && $request !== null) {
                $request->attributes->set(self::ATTRIBUTE_CODE, $record->code);
            }

            return $record?->code;
        } catch (Throwable) {
            // Jangan pernah memicu galat sekunder bila DB sedang bermasalah.
            return null;
        }
    }

    public static function deteksiPortal(?Request $request): string
    {
        if ($request === null) {
            return ErrorLog::PORTAL_SYS;
        }

        $path = ltrim($request->path(), '/');

        return match (true) {
            $path === 'admin' || str_starts_with($path, 'admin/') => ErrorLog::PORTAL_ADM,
            $path === 'guru' || str_starts_with($path, 'guru/') => ErrorLog::PORTAL_GR,
            $path === 'pengawas' || str_starts_with($path, 'pengawas/') || $path === 'p' || str_starts_with($path, 'p/') => ErrorLog::PORTAL_PGW,
            $path === 'siswa' || str_starts_with($path, 'siswa/') || str_starts_with($path, 'hadir/') => ErrorLog::PORTAL_SSW,
            $path === 'super' || str_starts_with($path, 'super/') => ErrorLog::PORTAL_SUP,
            default => ErrorLog::PORTAL_PUB,
        };
    }

    public static function diabaikan(Throwable $e): bool
    {
        return $e instanceof ValidationException
            || $e instanceof AuthenticationException;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function simpanBaru(array $attributes): ?ErrorLog
    {
        $portal = (string) $attributes['portal'];

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($attributes, $portal): ErrorLog {
                    $lastCode = ErrorLog::query()
                        ->where('portal', $portal)
                        ->orderByDesc('id')
                        ->lockForUpdate()
                        ->value('code');

                    $nextNumber = 1;
                    if (is_string($lastCode) && preg_match('/-(\d+)$/', $lastCode, $m) === 1) {
                        $nextNumber = ((int) $m[1]) + 1;
                    }

                    $attributes['code'] = sprintf('ER-%s-%03d', $portal, $nextNumber);

                    return ErrorLog::query()->create($attributes);
                });
            } catch (UniqueConstraintViolationException) {
                // Ulangi bila ada tabrakan nomor urut secara bersamaan.
                continue;
            }
        }

        return null;
    }

    private static function deteksiStatus(Throwable $e): int
    {
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode();
        }

        if ($e instanceof TokenMismatchException) {
            return 419;
        }

        if ($e instanceof AuthorizationException) {
            return 403;
        }

        return 500;
    }

    private static function ringkasPath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $normalized = str_replace('\\', '/', $path);

        if (str_starts_with($normalized, $base)) {
            $normalized = substr($normalized, strlen($base));
        }

        return Str::limit($normalized, 255, '');
    }

    private static function ringkasTrace(Throwable $e): string
    {
        $frames = [];

        foreach (array_slice($e->getTrace(), 0, 25) as $idx => $frame) {
            $file = self::ringkasPath($frame['file'] ?? null) ?? '[internal]';
            $line = $frame['line'] ?? 0;
            $call = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '');
            $frames[] = sprintf('#%d %s:%d — %s()', $idx, $file, $line, $call);
        }

        return Str::limit(implode("\n", $frames), 12000, "\n...(terpotong)");
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function bangunKonteks(?Request $request): ?array
    {
        if ($request === null) {
            return null;
        }

        $route = $request->route();
        $params = [];
        if ($route !== null) {
            foreach ($route->parameters() as $k => $v) {
                $params[$k] = is_object($v) && method_exists($v, 'getKey')
                    ? $v->getKey()
                    : (is_scalar($v) ? $v : get_debug_type($v));
            }
        }

        return [
            'route' => $route?->getName(),
            'params' => $params,
            'query' => self::sensorArray($request->query()),
            'input' => self::sensorArray($request->except(['_method'])),
        ];
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function sensorArray(array $data): array
    {
        $hasil = [];

        foreach ($data as $key => $value) {
            $keyLower = mb_strtolower((string) $key);
            $sensitif = false;

            foreach (self::SENSITIVE_KEYS as $needle) {
                if (str_contains($keyLower, $needle)) {
                    $sensitif = true;
                    break;
                }
            }

            if ($sensitif) {
                $hasil[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $hasil[$key] = self::sensorArray($value);
            } elseif (is_string($value)) {
                $hasil[$key] = Str::limit($value, 300);
            } else {
                $hasil[$key] = $value;
            }
        }

        return $hasil;
    }
}
