<?php

declare(strict_types=1);

namespace Tai\Shared\Infrastructure;

use Tai\Shared\Domain\UserFacingException;

final class Terminal
{
    public static function password(bool $fromStdin): string
    {
        if ($fromStdin) {
            $line = fgets(STDIN, 4096);
            if ($line === false) {
                throw new UserFacingException('No password was received on standard input.');
            }
            return rtrim($line, "\r\n");
        }
        if (PHP_OS_FAMILY === 'Windows') {
            throw new UserFacingException('On Windows, pipe a hidden Read-Host password using --password-stdin; see docs/LOCAL_SETUP.md.');
        }
        if (!stream_isatty(STDIN) || !function_exists('exec')) {
            throw new UserFacingException('A terminal or --password-stdin is required.');
        }
        $output = [];
        exec('stty -g 2>/dev/null', $output, $code);
        $mode = $output[0] ?? '';
        if ($code !== 0 || !preg_match('/^[a-f0-9:]+$/iD', $mode)) {
            throw new UserFacingException('Cannot hide terminal input. Use --password-stdin.');
        }
        exec('stty -echo 2>/dev/null', $unused, $code);
        if ($code !== 0) {
            throw new UserFacingException('Cannot hide terminal input. Use --password-stdin.');
        }
        try {
            fwrite(STDERR, 'Password: ');
            $password = self::password(true);
            fwrite(STDERR, "\nConfirm password: ");
            $confirmation = self::password(true);
            fwrite(STDERR, "\n");
        } finally {
            exec('stty ' . escapeshellarg($mode) . ' 2>/dev/null');
        }
        if (!hash_equals($password, $confirmation)) {
            throw new UserFacingException('Password confirmation does not match.');
        }
        return $password;
    }
}
