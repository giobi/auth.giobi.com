<?php
/**
 * Simple router for OAuth callbacks
 */

class Router {
    private $routes = [];
    private $default = null;

    public function add($path, $handler) {
        $this->routes[$path] = $handler;
    }

    public function setDefault($handler) {
        $this->default = $handler;
    }

    public function dispatch($uri) {
        $path = parse_url($uri, PHP_URL_PATH);

        // Try exact match first
        if (isset($this->routes[$path])) {
            require_once __DIR__ . '/../' . $this->routes[$path];
            return;
        }

        // Try prefix match
        foreach ($this->routes as $route => $handler) {
            if (str_starts_with($path, $route)) {
                require_once __DIR__ . '/../' . $handler;
                return;
            }
        }

        // Default handler
        if ($this->default) {
            require_once __DIR__ . '/../' . $this->default;
            return;
        }

        http_response_code(404);
        echo "Route not found: $path";
    }
}
