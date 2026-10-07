<?php

declare(strict_types=1);

namespace Opensolr\ChatBot;

/**
 * opensolr-chat-bot password <data_dir>: sets the admin password.
 */
final class Cli
{
    private const MIN_LENGTH = 12;
    private const MAX_LENGTH = 4096;

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        if (count($argv) !== 3 || $argv[1] !== 'password' || trim($argv[2]) === '') {
            fwrite(STDERR, "Usage: php vendor/bin/opensolr-chat-bot password <data_dir>\n");
            return 2;
        }
        $first = $this->ask('New admin password: ');
        if (strlen($first) < self::MIN_LENGTH || strlen($first) > self::MAX_LENGTH) {
            fwrite(STDERR, 'The password must have at least ' . self::MIN_LENGTH . " characters.\n");
            return 1;
        }
        $second = $this->ask('The same password again: ');
        if (!hash_equals($first, $second)) {
            fwrite(STDERR, "The two passwords are not the same. Nothing was saved.\n");
            return 1;
        }
        try {
            $store = new Store($argv[2]);
            $store->setAdminPassword(password_hash($first, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT));
        } catch (\Throwable $e) {
            fwrite(STDERR, 'The password could not be saved: ' . $e->getMessage() . "\n");
            return 1;
        }
        fwrite(STDOUT, 'The admin password is saved in ' . $store->file() . ". Every admin session was signed out.\n");
        fwrite(STDOUT, "Run this command as the user PHP runs as on the web server, so the web server can write the database.\n");
        return 0;
    }

    private function ask(string $prompt): string
    {
        fwrite(STDOUT, $prompt);
        $hide = DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec') && stream_isatty(STDIN);
        if ($hide) {
            shell_exec('stty -echo');
        }
        $line = fgets(STDIN);
        if ($hide) {
            shell_exec('stty echo');
            fwrite(STDOUT, "\n");
        }
        return rtrim(is_string($line) ? $line : '', "\r\n");
    }
}
