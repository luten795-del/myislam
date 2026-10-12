<?php
declare(strict_types=1);

/**
 * Drop-in error/exception logging for the MyIslam app.
 *
 * Usage: require this file near the top of any entry-point script (after
 * config/database.php is available), then call installErrorHandlers()
 * once. From then on, PHP warnings/notices, uncaught exceptions, and fatal
 * errors are written to the `system_errors` table so they show up on the
 * "System Health" tab in automation.php — instead of only ever living in
 * a server error log nobody looks at.
 *
 * This does NOT replace PHP's normal error_log — it writes to both, so
 * ops tooling that already tails the log file keeps working.
 */

function logSystemError(string $level, string $message, ?string $file = null, ?int $line = null, ?string $context = null): void
{
    // Always fall back to the native error log first — this must never
    // depend on the DB being reachable.
    error_log("[{$level}] {$message}" . ($file ? " in {$file}:{$line}" : ''));

    try {
        if (!function_exists('db')) {
            return;
        }
        $conn = db();
        if (!$conn instanceof PDO) {
            return;
        }
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $stmt = $conn->prepare("
            INSERT INTO system_errors (level, message, file, line, context, user_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $level,
            mb_substr($message, 0, 4000),
            $file,
            $line,
            $context ?? ($_SERVER['SCRIPT_NAME'] ?? null),
            $userId,
        ]);
    } catch (Throwable $e) {
        // Logging must never itself throw / break the request.
        error_log('logSystemError failed to write to DB: ' . $e->getMessage());
    }
}

function installErrorHandlers(): void
{
    set_error_handler(function (int $errno, string $errstr, string $errfile = '', int $errline = 0): bool {
        $level = match (true) {
            in_array($errno, [E_WARNING, E_USER_WARNING, E_DEPRECATED, E_USER_DEPRECATED], true) => 'warning',
            in_array($errno, [E_NOTICE, E_USER_NOTICE, E_STRICT], true) => 'notice',
            default => 'error',
        };
        logSystemError($level, $errstr, $errfile, $errline);
        return false; // let PHP's normal handling continue too
    });

    set_exception_handler(function (Throwable $e): void {
        logSystemError('error', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
    });

    register_shutdown_function(function (): void {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            logSystemError('error', $err['message'], $err['file'], $err['line']);
        }
    });
}