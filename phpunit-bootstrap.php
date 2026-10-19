<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url): array|false
    {
        return parse_url($url);
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(private string $code = '', private string $message = '', private array $data = [])
        {
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public function __construct(private mixed $data = null, private int $status = 200, private array $headers = [])
        {
        }

        public function get_data(): mixed
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }

        public function get_headers(): array
        {
            return $this->headers;
        }
    }
}

if (!class_exists('WP_CLI')) {
    class WP_CLI
    {
        public static array $commands = [];
        public static array $runCommands = [];
        public static array $confirmations = [];
        public static array $successMessages = [];
        public static array $logMessages = [];
        public static array $warningMessages = [];
        public static array $errorMessages = [];
        public static array $debugMessages = [];
        public static ?object $runCommandResponse = null;
        public static array $config = [];

        public static function get_php_binary(): string
        {
            return PHP_BINARY;
        }

        public static function get_config(string $key): mixed
        {
            return self::$config[$key] ?? null;
        }

        public static function add_command(string $name, callable $callback): void
        {
            self::$commands[] = [$name, $callback];
        }

        public static function runcommand(string $command, array $options = []): string|object
        {
            self::$runCommands[] = [$command, $options];

            if ($options['return'] === 'all') {
                return self::$runCommandResponse ?? (object) ['stdout' => '', 'stderr' => '', 'return_code' => 0];
            }

            return '';
        }

        public static function confirm(string $message, array $associativeArguments = []): void
        {
            self::$confirmations[] = [$message, $associativeArguments];
        }

        public static function success(string $message): void
        {
            self::$successMessages[] = $message;
        }

        public static function log(string $message): void
        {
            self::$logMessages[] = $message;
        }

        public static function warning(string $message): void
        {
            self::$warningMessages[] = $message;
        }

        public static function error(string $message): never
        {
            self::$errorMessages[] = $message;

            throw new \RuntimeException('WP-CLI exited with an error.');
        }

        public static function debug(string $message, string|bool $group = false): void
        {
            self::$debugMessages[] = [$message, $group];
        }

    }
}
