<?php

// This file is part of Minz.
// Copyright 2020-2025 Marien Fressinaud
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Minz;

/**
 * Coordinate the different parts of the framework core.
 *
 * The engine is responsible to coordinate a request with a router, in order to
 * return a response to the user, based on the logic of the application's
 * actions.
 *
 * @phpstan-import-type ViewPointer from Output\View
 *
 * @phpstan-import-type ResponseReturnable from Response
 */
class Engine
{
    private static ?Router $router = null;

    /**
     * @var array{
     *     'start_session': bool,
     *     'controller_namespace': ?string,
     *     'not_found_view_pointer': ?ViewPointer,
     *     'internal_server_error_view_pointer': ?ViewPointer,
     * }
     */
    private static array $options;

    /**
     * @param array{
     *     'start_session'?: bool,
     *     'controller_namespace'?: ?string,
     *     'not_found_view_pointer'?: ?ViewPointer,
     *     'internal_server_error_view_pointer'?: ?ViewPointer,
     * } $options
     */
    public static function init(Router $router, array $options = []): void
    {
        $clean_options = [];
        $clean_options['start_session'] = $options['start_session'] ?? false;
        $clean_options['controller_namespace'] = $options['controller_namespace'] ?? null;
        $clean_options['not_found_view_pointer'] = $options['not_found_view_pointer'] ?? null;
        $clean_options['internal_server_error_view_pointer'] = $options['internal_server_error_view_pointer'] ?? null;
        self::$options = $clean_options;

        self::initLogs();
        if (self::$options['start_session']) {
            self::startSession();
        }

        self::$router = $router;
    }

    public static function reset(): void
    {
        self::$router = null;
        self::$options = [
            'start_session' => false,
            'controller_namespace' => null,
            'not_found_view_pointer' => null,
            'internal_server_error_view_pointer' => null,
        ];
    }

    public static function router(): ?Router
    {
        return self::$router;
    }

    /**
     * Initialize the logs system and configure errors reporting.
     *
     * The log system is configured to pass the PID with each message. Logs are
     * printed to the standard error unless Configuration::$no_syslog_output is
     * true.
     *
     * Errors reporting is configured accordingly to the official
     * recommendations:
     *
     * - all errors are displayed/logged in development and test
     * - all except deprecated and strict errors are logged in production
     *
     * Note this method is called by the init() method.
     *
     * @see \Minz\Configuration::$no_syslog_output
     * @see https://www.php.net/manual/function.openlog
     * @see https://www.php.net/manual/errorfunc.configuration.php#ini.error-reporting
     * @see https://github.com/php/php-src/blob/master/php.ini-production
     */
    public static function initLogs(): void
    {
        // Configure the system logger.
        $app_name = Configuration::$app_name;
        if (Configuration::$no_syslog_output) {
            openlog($app_name, LOG_PID, LOG_USER);
        } else {
            openlog($app_name, LOG_PERROR | LOG_PID, LOG_USER);
        }

        // Configure error reporting
        $environment = Configuration::$environment;
        switch ($environment) {
            case 'development':
            case 'test':
                error_reporting(E_ALL);
                ini_set('display_errors', 'On');
                ini_set('display_startup_errors', 'On');
                ini_set('log_errors', 'On');
                break;

            case 'production':
            default:
                error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
                ini_set('display_errors', 'Off');
                ini_set('display_startup_errors', 'Off');
                ini_set('log_errors', 'On');
                break;
        }
    }

    /**
     * Set the session name to the app name, and start the session with a
     * correct configuration for the cookie.
     *
     * Note this method is called with `Lax` value by the init() method if the
     * `start_session` option is passed. You can call it manually if you
     * prefer.
     *
     * @param 'Lax'|'Strict'|'None' $samesite
     *
     * @see https://developer.mozilla.org/docs/Web/HTTP/Headers/Set-Cookie/SameSite
     */
    public static function startSession(string $samesite = 'Lax'): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $url_options = Configuration::$url_options;
        session_name(Configuration::$app_name);

        $cookie_params = [
            'lifetime' => 0,
            'path' => $url_options['path'],
            'secure' => $url_options['protocol'] === 'https',
            'httponly' => true,
            'samesite' => $samesite,
        ];

        // Some browsers don't accept cookies if domain is set to localhost
        // @see https://stackoverflow.com/a/1188145
        if ($url_options['host'] !== 'localhost') {
            $cookie_params['domain'] = $url_options['host'];
        }

        session_set_cookie_params($cookie_params);
        session_start();
    }

    /**
     * This method tries to always return a response to the user. If an error
     * happens in the logic of the application, a response with the adequate
     * HTTP code and a pertinent view is returned.
     *
     * "Not found" and "Internal server" errors views can be chosen via the
     * options. You should make sure the view pointers you pass exist. By
     * default, the errors are returned as text.
     *
     * @return ResponseReturnable
     */
    public static function run(Request $request): mixed
    {
        if (!self::$router) {
            $e = new Errors\LogicException('The Engine must be initialized before running.');
            return self::internalServerErrorResponse($e);
        }

        try {
            list(
                $route_pointer,
                $parameters
            ) = self::$router->match($request->method(), $request->path());
        } catch (Errors\RouteNotFoundError $e) {
            return self::notFoundResponse($e);
        }

        foreach ($parameters as $param_name => $param_value) {
            $request->setParam($param_name, $param_value);
        }

        try {
            return self::executeRoutePointer($route_pointer, $request);
        } catch (\Exception $e) {
            Log::error((string)$e);
            return self::internalServerErrorResponse($e);
        }
    }

    /**
     * @return ResponseReturnable
     */
    private static function executeRoutePointer(string $route_pointer, Request $request): mixed
    {
        $namespace = self::$options['controller_namespace'];

        list($controller_name, $action_name) = explode('#', $route_pointer);
        $controller_name = str_replace('/', '\\', $controller_name);

        if ($namespace === null) {
            $app_name = Configuration::$app_name;
            $namespace = "\\{$app_name}";
        }

        $namespaced_controller = "{$namespace}\\{$controller_name}";

        try {
            $controller = new $namespaced_controller();
        } catch (\Error $e) {
            throw new Errors\ControllerError(
                "{$namespaced_controller} controller class cannot be found."
            );
        }

        if (!is_callable([$controller, $action_name])) {
            throw new Errors\ActionError(
                "{$action_name} action cannot be called on {$namespaced_controller} controller."
            );
        }

        try {
            $response = $controller->$action_name($request);
        } catch (\Exception $e) {
            // Execute errors handlers defined at the controller level. A
            // handler allows to execute code on specific errors.
            $errors_handlers = self::loadErrorsHandlers($controller, $action_name, $e::class);

            foreach ($errors_handlers as $error_handler) {
                $response = $error_handler->invokeArgs($controller, [$request]);

                // If the handler returns a response, returns it immediately.
                if ($response instanceof Response) {
                    return $response;
                }
            }

            throw $e;
        }

        if (!($response instanceof Response) && !($response instanceof \Generator)) {
            throw new Errors\ActionError(
                "{$action_name} action in {$namespaced_controller} controller does not return a Response."
            );
        }

        return $response;
    }

    /**
     * Return a list of methods defined with the Controller\ErrorHandler
     * attribute for the given action and error class.
     *
     * @param class-string<\Exception> $class_error
     *
     * @return \ReflectionMethod[]
     */
    private static function loadErrorsHandlers(
        object $controller,
        string $action_name,
        string $class_error
    ): array {
        // Load all the methods of the controller class.
        $class_reflection = new \ReflectionClass($controller::class);
        $methods = $class_reflection->getMethods();

        $errors_handlers = [];

        foreach ($methods as $method) {
            // Keep only methods defined with the Controller\ErrorHandler attribute.
            $error_handler_attributes = $method->getAttributes(Controller\ErrorHandler::class);

            foreach ($error_handler_attributes as $error_handler_attribute) {
                $error_handler = $error_handler_attribute->newInstance();

                if (!empty($error_handler->only) && !in_array($action_name, $error_handler->only)) {
                    // Keep only handlers defined for the current action, or if
                    // if $only is empty (i.e. meaning the handler applies to
                    // all the methods).
                    continue;
                }

                if (!is_a($class_error, $error_handler->class_error, true)) {
                    // Keep only handlers defined for the given $class_error
                    continue;
                }

                $errors_handlers[] = $method;
            }
        }

        return $errors_handlers;
    }

    private static function notFoundResponse(\Exception $error): Response
    {
        if (self::$options['not_found_view_pointer']) {
            $output = new Output\View(
                self::$options['not_found_view_pointer'],
                ['error' => $error]
            );
        } else {
            $output = new Output\Text((string)$error);
        }

        return new Response(404, $output);
    }

    private static function internalServerErrorResponse(\Exception $error): Response
    {
        if (self::$options['internal_server_error_view_pointer']) {
            $output = new Output\View(
                self::$options['internal_server_error_view_pointer'],
                ['error' => $error]
            );
        } else {
            $output = new Output\Text((string)$error);
        }

        return new Response(500, $output);
    }
}
