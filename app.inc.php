<?php
    
    $configuration = [
        'settings' => [
            'displayErrorDetails' => true,
        ],
    ];

    // Create container
    $container = new \Slim\Container($configuration);

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
    } else {
        $app->get('/', function($request, $response, $args) {
            return $this->view->render($response, 'firstrun.twig');
        });

        $app->post('/firstRun', function($request, $response, $args) {
            $userDetails = $request->getParsedBody();
            if(createAdminUser($userDetails)) {
                return $this->view->render($response, 'admin.twig');
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
?>