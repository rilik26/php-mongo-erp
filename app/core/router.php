<?php
/**
 * ------------------------------------------------------------
 * Router
 * ------------------------------------------------------------
 * URL'yi çözümler ve ilgili controller + action'ı çalıştırır.
 * Modül bazlı dinamik routing yapar.
 */

class Router
{
    /**
     * Request nesnesi
     * @var Request
     */
    protected Request $request;

    /**
     * Constructor
     *
     * @param Request $request
     */
    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * İsteği dağıtır (dispatch)
     *
     * @return Response
     */
    public function dispatch(): Response
    {
        $response = new Response();

        // URI parçalama
        $uri = trim($this->request->uri(), '/');

        // Proje alt dizinde çalışıyorsa base path'i temizle
        $basePath = trim(dirname($_SERVER['SCRIPT_NAME']), '/');

        if ($basePath && str_starts_with($uri, $basePath)) {
            $uri = trim(substr($uri, strlen($basePath)), '/');
        }

        $segments = $uri === '' ? [] : explode('/', $uri);


        // Varsayılanlar
        $module = $segments[0] ?? 'user';
        $action = $segments[1] ?? 'index';

        // Controller sınıf ve dosya bilgileri
        $controllerClass = ucfirst($module) . 'Controller';
        $controllerFile  = BASE_PATH . '/app/modules/' . $module . '/' . strtolower($controllerClass) . '.php';

        // Controller dosyası var mı?
        if (!file_exists($controllerFile)) {
            return $response
                ->status(404)
                ->setContent('Controller bulunamadı');
        }

        require_once $controllerFile;

        // Controller sınıfı var mı?
        if (!class_exists($controllerClass)) {
            return $response
                ->status(404)
                ->setContent('Controller sınıfı bulunamadı');
        }

        $controller = new $controllerClass($this->request);

        // Action metodu var mı?
        if (!method_exists($controller, $action)) {
            return $response
                ->status(404)
                ->setContent('Action bulunamadı');
        }

        // Action çalıştırılır
        $result = $controller->$action();

        // Controller Response döndürdüyse
        if ($result instanceof Response) {
            return $result;
        }

        // String döndürdüyse body olarak yaz
        if (is_string($result)) {
            return $response->setContent($result);
        }

        // Beklenmeyen dönüş
        return $response
            ->status(500)
            ->setContent('Geçersiz response tipi');
    }
}
