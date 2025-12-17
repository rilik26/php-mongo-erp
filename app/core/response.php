<?php
/**
 * ------------------------------------------------------------
 * Response
 * ------------------------------------------------------------
 * Controller'dan dönen veriyi HTTP response'a çevirir
 */

class Response
{
    protected int $statusCode = 200;
    protected array $headers = [];
    protected mixed $body = null;

    /**
     * Body set edilir
     */
    public function setBody(mixed $body): self
    {
        $this->body = $body;
        return $this;
    }

    /**
     * setContent alias (Router uyumu için)
     */
    public function setContent(mixed $content): self
    {
        return $this->setBody($content);
    }

    /**
     * HTTP status code set edilir
     */
    public function setStatus(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    /**
     * Header eklenir
     */
    public function addHeader(string $key, string $value): self
    {
        $this->headers[$key] = $value;
        return $this;
    }

    /**
     * Response'u tarayıcıya gönderir
     */
    public function send(): void
    {
        http_response_code($this->statusCode);

        foreach ($this->headers as $key => $value) {
            header("$key: $value");
        }

        // Body tipine göre çıktı üret
        if (is_array($this->body)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($this->body, JSON_UNESCAPED_UNICODE);
            return;
        }

        if (is_string($this->body)) {
            echo $this->body;
            return;
        }

        if ($this->body === null) {
            return;
        }

        // Desteklenmeyen tip
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Geçersiz response tipi';
    }
}
