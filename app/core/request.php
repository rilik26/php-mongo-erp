<?php
/**
 * ------------------------------------------------------------
 * Request
 * ------------------------------------------------------------
 * HTTP isteğini temsil eder.
 * Süper global'leri soyutlar.
 */

class Request
{
    /**
     * HTTP method (GET, POST, PUT, DELETE...)
     * @var string
     */
    protected string $method;

    /**
     * İstek URI
     * @var string
     */
    protected string $uri;

    /**
     * GET verileri
     * @var array
     */
    protected array $query = [];

    /**
     * POST verileri
     * @var array
     */
    protected array $body = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->uri    = $_SERVER['REQUEST_URI'] ?? '/';
        $this->query  = $_GET ?? [];
        $this->body   = $_POST ?? [];
    }

    /**
     * HTTP method döner
     *
     * @return string
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * URI döner (query string temizlenmiş)
     *
     * @return string
     */
    public function uri(): string
    {
        return strtok($this->uri, '?');
    }

    /**
     * GET parametresi al
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function query(string $key, $default = null)
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * POST parametresi al
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function input(string $key, $default = null)
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * Tüm GET verileri
     *
     * @return array
     */
    public function allQuery(): array
    {
        return $this->query;
    }

    /**
     * Tüm POST verileri
     *
     * @return array
     */
    public function allInput(): array
    {
        return $this->body;
    }
}
