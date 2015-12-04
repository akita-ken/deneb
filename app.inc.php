<?php
    
    $configuration = [
        'settings' => [
            'displayErrorDetails' => true,
        ],
    ];

    // Create container
    $container = new \Slim\Container($configuration);

    // Create session factory
    $session_factory = new \Aura\Session\SessionFactory;
    $session = $session_factory->newInstance($_COOKIE);

    // Register component on container
    $container['view'] = function ($c) {
        $view = new \Slim\Views\Twig('templates', [
            'cache' => 'cache',
            'auto_reload' => 'false'
        ]);
        $view->addExtension(new \Slim\Views\TwigExtension(
            $c['router'],
            $c['request']->getUri()
        ));

        return $view;
    };

    // Create app
    $app = new \Slim\App($container);
    
    // Render Twig template in route
    if (firstRunCheck()) {
        $app->get('/', function($request, $response, $args) {
            return $this->view->render($response, 'base.twig');
        });

        $app->get('/admin', function($request, $response, $args) use ($session) {
            $segment = $session->getSegment('deneb');
            if ($segment->get('auth')) {
                if (!file_exists('pages')) {
                    mkdir('pages', 0755);
                }
                return $this->view->render($response, 'admin.twig');
            } else {
                return $response->withRedirect('/deneb');
            }
            
        });
    } else {
        $app->get('/', function($request, $response, $args) {
            return $this->view->render($response, 'firstrun.twig');
        });

        $app->post('/firstRun', function($request, $response, $args) use ($session) {
            $userDetails = $request->getParsedBody();
            if(createAdminUser($userDetails)) {
                $segment = $session->getSegment('deneb');
                $segment->set('username', $userDetails['username']);
                $segment->set('auth', true);

                return $response->withRedirect('/deneb/admin', 301);
            } else {
                return $this->view->render($response, 'error.twig');
            }
        });
    }

    // Run app

    $app->run();

    function firstRunCheck() {
        static $configFileExists = false;

        if (!$configFileExists) {
            if (file_exists('config.ini')) {
                $configFileExists = true;
            } 
            return $configFileExists;
        } else {
            return $configFileExists;
        }
    }

    function createAdminUser($userDetails) {
        $config = new Config_Lite('config.ini');

        $config->set('admin', 'username', $userDetails['username'])
            ->set('admin', 'password', password_hash($userDetails['password'], PASSWORD_DEFAULT))
            ->set('admin', 'email', $userDetails['email'])
            ->set('admin', 'displayname', $userDetails['displayname']);

        $config->save();

        return true;
    }

    function loadPages() {
        
    }
?>