<?php

    $configuration = [
        'settings' => [
            'displayErrorDetails' => true,
        ]
    ];

    // Create container
    $container = new \Slim\Container($configuration);

    // Create session factory
    $session_factory = new \Aura\Session\SessionFactory;
    $session = $session_factory->newInstance($_COOKIE);

    // Register component on container
    $container['view'] = function($c) {
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

    // Set page path
    $container['pagePath'] = 'pages';

    // Start PHP session
    session_start();

    // Create app
    $app = new \Slim\App($container);

    // Add slim-csrf component to application middleware
    $app->add(new \Slim\Csrf\Guard);

    // Render Twig template in route
    if (firstRunCheck()) {
        $app->get('/', function($request, $response, $args) {
            return $this->view->render($response, 'base.twig');
        });

        $app->get('/admin', function($request, $response, $args) use ($session) {
            if (sessionCheck($session)) {
                if (!file_exists('pages')) {
                    mkdir('pages', 0755);
                }
                $navigation = createNavigation(loadPages($this->pagePath));

                return $this->view->render($response, 'admin.twig', [
                    'flashSuccess' => $session->getSegment('deneb')->getFlash('flashSuccess'),
                    'navigation' => $navigation
                ]);
            } else {
                return $response->withRedirect($this->router->pathFor('login'), 301);
            }
        })->setName('admin');

        $app->get('/login', function($request, $response, $args) {
            // CSRF token name and value
            return $this->view->render($response, 'login.twig', [
                'name' => $request->getAttribute('csrf_name'),
                'value' => $request->getAttribute('csrf_value')
            ]);
        })->setName('login');

        $app->post('/auth', function($request, $response, $args) use ($session) {
          $loginDetails = $request->getParsedBody();
          if (doAuthentication($loginDetails)) {
            $segment = $session->getSegment('deneb');
            $segment->set('username', $loginDetails['username']);
            $segment->set('auth', true);

            return $response->withRedirect('/deneb/admin', 301);
          }
        });

        $app->post('/admin/update', function($request, $response, $args) use ($session) {
            if (sessionCheck($session)) {
                $segment = $session->getSegment('deneb');
                $currentPath = $segment->getFlash('path');

                $pageData = $request->getParsedBody();
                $content = array_pop($pageData);
                $pageData = array_splice($pageData, 0, -2);

                if (requiredValidation($pageData['path'])) {
                    $pageData['path'] = $this->pagePath . $pageData['path'] . '.md';
                    $pageData['hash'] = hash('crc32b', $pageData['path']);

                    if ($currentPath != $pageData['path']) {
                        if(!unlink($currentPath)) {
                            $segment->setFlash('flashError', 'Unable to remove old file:' . $currentPath);
                            $session->commit();
                            return $response->withRedirect($this->router->pathFor('edit', [
                                'hash' => $pageData['hash']
                            ]), 301);
                        }
                    }
                    writePage($pageData, $content);

                    $segment->setFlash('flashSuccess', 'Page edited successfully');
                    $session->commit();
                    return $response->withRedirect($this->router->pathFor('admin'), 301);
                } else {
                    $segment->setFlash('flashError', 'Page path is a required field');
                    $session->commit();
                    return $response->withRedirect($this->router->pathFor('edit', [
                        'hash' => $pageData['hash']
                    ]), 301);
                }
            } else {
                return $response->withRedirect($this->router->pathFor('login'), 301);
            }
        });
        $app->post('/admin/create', function($request, $response, $args) use ($session) {
            if (sessionCheck($session)) {
                $pageData = $request->getParsedBody();
                $content = array_pop($pageData);
                $pageData = array_splice($pageData, 0, -2);
                $segment = $session->getSegment('deneb');

                if (requiredValidation($pageData['path'])) {
                    $pageData['path'] = $this->pagePath . $pageData['path'] . '.md';
                    $pageData['hash'] = hash('crc32b', $pageData['path']);
                    writePage($pageData, $content);

                    $segment->setFlash('flashSuccess', 'Page created successfully');
                    $session->commit();
                    return $response->withRedirect($this->router->pathFor('admin'), 301);
                } else {
                    $segment->set('createForm', $pageData);
                    $segment->setFlash('flashError', 'Page path is a required field');

                    $session->commit();
                    return $response->withRedirect($this->router->pathFor('new'), 301);
                }
            } else {
                return $response->withRedirect($this->router->pathFor('login'), 301);
            }
        });
        $app->get('/admin/new', function($request, $response, $args) use ($session) {
            if (sessionCheck($session)) {
                $navigation = createNavigation(loadPages($this->pagePath));

                return $this->view->render($response, 'new.twig', [
                    'flashError' => $session->getSegment('deneb')->getFlash('flashError'),
                    'navigation' => $navigation,
                    'name' => $request->getAttribute('csrf_name'),
                    'value' => $request->getAttribute('csrf_value')
                ]);
            } else {
                return $response->withRedirect($this->router->pathFor('login'), 301);
            }
        })->setName('new');
        $app->get('/logout', function($request, $response, $args) use ($session) {
          $session->destroy();

          return $response->withRedirect('/deneb', 301);
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

    function requiredValidation($input) {
        if ($input == '' || $input == null) {
            return false;
        }
        return true;
    }

    function sessionCheck($session) {
        $segment = $session->getSegment('deneb');
        return $segment->get('auth');
    }

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

    function loadPages($path) {
        $pages = array();
        $exclude = [".DS_Store", ".", "..", "Desktop.ini", "Thumbs.db"];

        $dir = new DirectoryIterator($path);
        foreach ($dir as $file) {
            if (!in_array($file->__toString(), $exclude)) {
                if ($file->isFile()) {
                    $pages[$file->getFilename()] = $file->getPathname();
                } else if ($file->isDir()) {
                    $pages[$file->getFilename()] = loadPages($file->getPathname());
                }
            }
        }
        return $pages;
    }

    function createRoutes($pages, $app) {
        foreach ($pages as $page => $path) {
            if (!is_array($path)) {
                $app->get(substr($path, 5, -3), function($request, $response, $args) use ($path) {
                    $contents = readContents($path);
                    return $this->view->render($response, 'page.twig', [
                        'contents' => $contents
                    ]);
                });
            } else {
                createRoutes($path, $app);
            }
        }
    }

    function createNavigation($pages) {
        $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($pages));
        $navigation = array();

        foreach ($iterator as $leafKey => $leafValue) {
            $keys = array();
            $meta = readMeta($leafValue);

            if ($iterator->getDepth() == 0) {
                $navigation[$meta['linkname']] = $meta['category'] . ',' . $meta['hash'];
            } else {
                foreach (range(0, $iterator->getDepth() - 1) as $depth) {
                    $keys[] = $iterator->getSubIterator($depth)->key();
                }

                $path = '/'.join('/', $keys);

                if (array_key_exists($path, $navigation)) {
                    $navigation[$path][$meta['linkname']] = $meta['category']. ',' . $meta['hash'];
                } else {
                    $navigation[$path] = array();
                    $navigation[$path][$meta['linkname']] = $meta['category']. ',' . $meta['hash'];
                }
            }
        }
        return $navigation;
    }
    function writePage($pageData, $content) {
        $file = fopen($pageData['path'], 'w');
        array_shift($pageData);
        $pageData = json_encode($pageData, JSON_PRETTY_PRINT);
        $page = $pageData . "\r\n\r\n--\r\n" . $content;
        fwrite($file, $page);
        fclose($file);
    }

    function readMeta($path) {
        $fileContents = file_get_contents($path);
        $contents = explode("--", $fileContents);
        $pageMeta = json_decode(array_shift($contents), true);

        if (empty($pageMeta['category'])) {
            $pageMeta['category'] = "Uncategorised";
        }
        return $pageMeta;
    }

    function readContents($path) {
        $page = array();
        $fileContents = file_get_contents($path);
        $contents = explode("--", $fileContents);
        $page['meta'] = json_decode(array_shift($contents), true);
        $page['contents'] = implode($contents);
        return $page;
    }

    function doAuthentication($loginDetails) {
      $config = new Config_Lite('config.ini');
      if (($loginDetails['username'] == $config->get('admin', 'username')) && password_verify($loginDetails['password'], $config->get('admin', 'password'))) {
        return true;
      }
      return false;
    }
?>
