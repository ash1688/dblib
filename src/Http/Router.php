<?php

declare(strict_types=1);

namespace Dblib\Http;

/**
 * Minimal exact-match router. Routes are registered as
 * (method, path) => [ControllerClass, 'method'].
 */
final class Router
{
    /** @var array<string,callable|array{0:class-string,1:string}> */
    private array $routes = [];

    public function __construct(private readonly string $basePath)
    {
    }

    public function get(string $path, array $handler): void
    {
        $this->routes['GET ' . $path] = $handler;
    }

    public function post(string $path, array $handler): void
    {
        $this->routes['POST ' . $path] = $handler;
    }

    public function dispatch(Request $request): void
    {
        $key = $request->method . ' ' . rtrim($request->path, '/');
        if ($key === $request->method . ' ') {
            $key = $request->method . ' /';
        }

        $handler = $this->routes[$key] ?? $this->routes[$request->method . ' ' . $request->path] ?? null;

        if ($handler === null) {
            (Response::html('<h1>404 Not Found</h1>', 404))->send();
            return;
        }

        [$class, $method] = $handler;
        $controller = new $class();
        $response = $controller->$method($request);

        if ($response instanceof Response) {
            $response->send();
        }
    }
}
