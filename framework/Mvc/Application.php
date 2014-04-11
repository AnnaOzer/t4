<?php

namespace T4\Mvc;

use T4\Core\Config;
use T4\Core\Exception;
use T4\Core\Flash;
use T4\Core\Session;
use T4\Core\Std;
use T4\Core\TSingleton;
use T4\Dbal\Connection;

/**
 * Class Application
 * @package T4\Mvc
 * @property \App\Models\User $user
 * @property \T4\Mvc\AssetsManager $assets
 */
class Application
{
    use TSingleton;

    /*
     * Protected properties for getters and setters
     */
    protected $__user;

    /*
     * Public properties
     */

    public $path = \ROOT_PATH_PROTECTED;

    /**
     * @var \T4\Core\Config
     */
    public $config;

    /**
     * @var \T4\Core\Std
     */
    public $db;

    /**
     * @var \T4\Core\Flash
     */
    public $flash;

    /**
     * @var \T4\Core\Std
     */
    public $extensions;

    /**
     * Возвращает абсолютный путь до папки приложения
     * Обычно это папка protected
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * Возвращает конфиг роутинга приложения
     * @return \T4\Core\Config Объект конфига роутинга
     */
    public function getRouteConfig()
    {
        return new Config($this->getPath() . DS . 'routes.php');
    }

    /**
     * Проверка существования модуля веб-приложения
     * @param string $module
     * @return bool
     */
    public function existsModule($module = '')
    {
        if (empty($module))
            return true;
        $modulePath = $this->getPath() . DS . 'Modules' . DS . ucfirst($module);
        return is_dir($modulePath) && is_readable($modulePath);
    }

    /**
     * Проверка существования контроллера в веб-приложении или его модуле
     * @param string $module
     * @param string $controller
     * @return bool
     */
    public function existsController($module = '', $controller = Router::DEFAULT_CONTROLLER)
    {
        $controllerClassName = (empty($module) ? '\\App\\Controllers\\' : '\\App\\Modules\\' . ucfirst($module) . '\\Controllers\\') . ucfirst($controller);
        return $this->existsModule($module) && class_exists($controllerClassName) && is_subclass_of($controllerClassName, '\\T4\\Mvc\\Controller');
    }

    /**
     * Конструктор
     * Инициализация:
     * - сессий
     * - конфигурации приложения
     * - секций и блоков
     * - создание подключений к БД
     * - расширений
     */
    protected function __construct()
    {
        Session::init();
        $this->flash = new Flash();

        try {

            /*
             * Application config setup
             * Setup sections and blocks
             */
            $this->config = new Config($this->getPath() . DS . 'config.php');
            $this->config->sections = new Config($this->getPath() . DS . 'sections.php');
            $this->config->blocks = new Config($this->getPath() . DS . 'blocks.php');

            /*
             * DB connections setup
             */
            $this->db = new Std;
            foreach ($this->config->db as $connection => $connectionConfig) {
                $this->db->{$connection} = new Connection($connectionConfig);
            }

            /*
             * Extensions setup and initialize
             */
            $this->extensions = new Std;
            if (isset($this->config->extensions)) {
                foreach ($this->config->extensions as $extension => $options) {
                    $extensionClassName = 'Extensions\\' . ucfirst($extension) . '\\Extension';
                    if (class_exists('\\App\\' . $extensionClassName)) {
                        $extensionClassName = '\\App\\' . $extensionClassName;
                    } else {
                        $extensionClassName = '\\T4\\' . $extensionClassName;
                    }
                    $this->extensions->{$extension} = new $extensionClassName($options);
                    $this->extensions->{$extension}->setApp($this);
                    $this->extensions->{$extension}->init();
                }
            }

        } catch (Exception $e) {
            echo $e->getMessage();
            die;
        }
    }

    /**
     * Запуск веб-приложения
     * и формирование ответа
     */
    public function run()
    {
        try {

            $route = Router::getInstance()->parseUrl($_GET['__path']);
            $controller = $this->createController($route->module, $route->controller);
            $controller->action($route->action, $route->params);

            switch ($route->format) {
                case 'json':
                    header('Content-Type: application/json');
                    echo json_encode($controller->getData());
                    die;
                default:
                case 'html':
                    header('Content-Type: text/html; charset=utf-8');
                    $controller->view->display($route->action . '.' . $route->format, $controller->getData());
                    break;
            }

        } catch (Exception $e) {
            echo $e->getMessage();
            die;
        }
    }

    /**
     * Вызов блока
     * @param string $path Внутренний путь до блока
     * @param array $params Параметры, передаваемые блоку
     * @return mixed Результат рендера блока
     * @throws \T4\Core\Exception
     */
    public function callBlock($path, $params = [])
    {
        $router = Router::getInstance();
        $route = $router->splitInternalPath($path);
        $route->params->merge($params);

        $canonicalPath = $router->mergeInternalPath($route);
        if (!isset($this->config->blocks) || !isset($this->config->blocks[$canonicalPath]))
            throw new Exception('No config for block ' . $canonicalPath);

        $controller = $this->createController($route->module, $route->controller);
        $controller->action($route->action, $route->params);
        return $controller->view->render($route->action . '.block.html', $controller->getData());
    }

    /**
     * Возвращает экземпляр контроллера
     * @param string $module
     * @param string $controller
     * @throws \T4\Core\Exception
     * @return \T4\Mvc\Controller
     */
    protected function createController($module, $controller)
    {
        if (!$this->existsController($module, $controller))
            throw new Exception('Controller ' . $controller . ' does not exist');

        if (empty($module))
            $controllerClass = '\\App\\Controllers\\' . $controller;
        else
            $controllerClass = '\\App\\Modules\\' . ucfirst($module) . '\\Controllers\\' . ucfirst($controller);

        $controller = new $controllerClass;
        return $controller;
    }

    /**
     * Получение некоторых свойств через магию
     * чтобы развязать узел с бесконечным вызовом конструктора
     * и сделать их инициализацию ленивой
     * @param $key
     * @return mixed
     */
    public function __get($key)
    {
        switch ($key) {
            // current user
            // TODO: если пользователь не залогинен, так и будет бесконечно его запрашивать из базы! (возможно...)
            case 'user':
                if (null === $this->__user) {
                    if (class_exists('\\App\Components\Auth\Identity')) {
                        $identity = new \App\Components\Auth\Identity();
                        $this->__user = $identity->getUser();
                    } else {
                        return null;
                    }
                }
                return $this->__user;
                break;
            case 'assets':
                return AssetsManager::getInstance();
                break;

        }
    }

    public function __isset($key)
    {
        switch ($key) {
            // current user
            case 'user':
                return null !== $this->user;
                break;
            case 'assets':
                return true;
                break;
        }
    }

}