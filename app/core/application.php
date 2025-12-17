<?php
/**
 * ------------------------------------------------------------
 * Application
 * ------------------------------------------------------------
 * Uygulamanın yaşam döngüsünü yöneten ana sınıf.
 * Front Controller (index.php) tarafından başlatılır.
 */

class Application
{
    /**
     * Uygulama konfigürasyonu
     * @var array
     */
    protected array $config = [];

    /**
     * Constructor
     * Uygulama ayarlarını alır
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Uygulamayı çalıştırır
     * Request -> Router -> Response akışını başlatır
     *
     * @return void
     */
    public function run(): void
    {
        try 
        {
            // HTTP Request nesnesini oluştur
            $request = new Request();

            // Router'ı başlat ve isteği çözümle
            $router = new Router($request);
            $response = $router->dispatch();

            // Response'u tarayıcıya gönder
            $response->send();
        } 
        catch (Throwable $e) 
        {
            // Beklenmeyen hatalarda temel hata cevabı
            $this->handleException($e);
        }
    }

    /**
     * Uygulama genel hata yakalayıcısı
     *
     * @param Throwable $e
     * @return void
     */
    protected function handleException(Throwable $e): void
    {
        http_response_code(500);

        echo '<h1>Uygulama Hatası</h1>';
        echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
    }
}
