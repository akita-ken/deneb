<?php

require 'ImageTwig.php';
require 'utility.php';

use Aptoma\Twig\Extension\MarkdownExtension;
use Aptoma\Twig\Extension\MarkdownEngine;

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

// Create Markdown engine
$container['engine'] = new MarkdownEngine\MichelfMarkdownEngine();

// Load site templates
$container['templates'] = loadTemplates();

// Load page templates
$container['pageTemplates'] = function($c) {
    return loadPageTemplates($c['templatePath']);
};

// Set page path
$container['pagePath'] = 'pages';

// Set upload path
$container['uploadPath'] = 'uploads';

// Set default template
$container['template'] = getApplicationSettings('template');

// Set template path service
$container['templatePath'] = function($c) {
    return $c['templates'][$c['template']];
};

// Register component on container
$container['view'] = function($c) {
    $view = new \Slim\Views\Twig($c['templatePath'], [
        'cache' => 'cache',
        'auto_reload' => 'false'
    ]);
    $view->addExtension(new \Slim\Views\TwigExtension(
        $c['router'],
        $c['request']->getUri()
    ));
    $view->addExtension(new ImageTwig());
    $view->addExtension(new Twig_Extension_StringLoader());
    $view->addExtension(new MarkdownExtension($c['engine']));

    return $view;
};

// Start PHP session
session_start();

// Create app
$app = new \Slim\App($container);

// Add slim-csrf component to application middleware
$app->add(new \Slim\Csrf\Guard);

// Render Twig template in route
if (firstRunCheck()) 
/**
    This block runs if firstRunCheck() returns true
*/
{
    setConfig($session);

    $app->get('/admin', function($request, $response, $args) use ($session) {
        if (sessionCheck($session)) {
            if (!file_exists('pages')) {
                mkdir('pages', 0755);
            }
            $navigation = createNavigation(loadPages($this->pagePath), $this->pagePath, true);
            $container['templates'] = loadTemplates();

            $segment = $session->getSegment('deneb');

            $flashWarn = $segment->get('persistentWarn');

            // clear out persistent warnings (from app init)
            $segment->set('persistentWarn', null);

            $flashWarn .= $segment->getFlash('flashWarn');

            foreach ($this->templates as $template => $path) {
                if (!$path) {
                    unset($container['templates'][$template]);
                    $flashWarn .= '<li>The template \'' . $template . '\' is missing one or more required files.</li>';
                }
            }

            $segment->setFlash('flashWarn', $flashWarn);
            // $session->commit();

            $config = new Config_Lite(CONFIG_FILE_PATH);

            $headerTextRaw = $config->get('template', 'headerText', false);
            $headerText = str_replace('{{ baseUrl }}', $request->getUri()->getBasePath(), $headerTextRaw);
            $headerText = str_replace('{{ templatePath }}', $this->templatePath, $headerText);

            $footerTextRaw = $config->get('template', 'footerText', false);
            $footerText = str_replace('{{ baseUrl }}', $request->getUri()->getBasePath(), $headerTextRaw);
            $footerText = str_replace('{{ templatePath }}', $this->templatePath, $headerTextRaw);

            $javascriptSnippetRaw = $config->get('template', 'javascriptSnippet', false);

            return $this->view->render($response, 'admin.twig', [
                'flashSuccess' => $segment->getFlash('flashSuccess'),
                'flashWarn' => $segment->getFlash('flashWarn'),
                'flashError' => $segment->getFlash('flashError'),
                'baseUrl' => $request->getUri()->getBasePath(),
                'templates' => $container['templates'],
                'navigation' => $navigation,
                'activeTemplate' => $this->template,
                'templatePath' => $this->templatePath,
                'headerText' => $headerText,
                'footerText' => $footerText,
                'headerTextRaw' => $headerTextRaw,
                'footerTextRaw' => $footerTextRaw,
                'javascriptSnippetRaw' => $javascriptSnippetRaw,
                'name' => $request->getAttribute('csrf_name'),
                'value' => $request->getAttribute('csrf_value')
            ]);
        } else {
            return $response->withRedirect($this->router->pathFor('login'), 301);
        }
    })->setName('admin');

    $app->post('/admin', function($request, $response, $args) use ($session) {
        if (sessionCheck($session)) {
            $config = new Config_Lite(CONFIG_FILE_PATH);
            $settings = $request->getParsedBody();
            if ($settings['template'] != $this->template) {
                $container['template'] = $settings['template'];

                $config->set('application', 'template', $settings['template']);
            }

            if ($settings['header-text'] != $config->get('template', 'headerText', false)) {
                $config->set('template', 'headerText', $settings['header-text']);
            }

            if ($settings['footer-text'] != $config->get('template', 'footerText', false)) {
                $config->set('template', 'footerText', $settings['footer-text']);
            }

            if ($settings['javascript-snippet'] != $config->get('template', 'javascriptSnippet', false)) {
                $config->set('template', 'javascriptSnippet', $settings['javascript-snippet']);
            }

            $config->save();
            return $response->withRedirect($this->router->pathFor('admin'), 301);
        } else {
            return $response->withRedirect($this->router->pathFor('login'), 301);
        }
    });

    $app->get('/admin/stats', function($request, $response, $args) use ($session) {
        if (sessionCheck($session)) {
            $navigation = createNavigation(loadPages($this->pagePath), $this->pagePath, true);

            $config = new Config_Lite(CONFIG_FILE_PATH);

            $headerTextRaw = $config->get('template', 'headerText', false);
            $headerText = str_replace('{{ baseUrl }}', $request->getUri()->getBasePath(), $headerTextRaw);
            $headerText = str_replace('{{ templatePath }}', $this->templatePath, $headerText);

            $footerTextRaw = $config->get('template', 'footerText', false);
            $footerText = str_replace('{{ baseUrl }}', $request->getUri()->getBasePath(), $headerTextRaw);
            $footerText = str_replace('{{ templatePath }}', $this->templatePath, $headerTextRaw);

            return $this->view->render($response, 'stats.twig', [
                'baseUrl' => $request->getUri()->getBasePath(),
                'templatePath' => $this->templatePath,
                'navigation' => $navigation,
                'headerText' => $headerText,
                'footerText' => $footerText,
                ]);
        } else {
            return $response->withRedirect($this->router->pathFor('login'), 301);
        }
    })->setName('stats');

    $app->get('/login', function($request, $response, $args) use ($session) {
        // CSRF token name and value
        return $this->view->render($response, 'login.twig', [
            'baseUrl' => $request->getUri()->getBasePath(),
            'templatePath' => $this->templatePath,
            'name' => $request->getAttribute('csrf_name'),
            'value' => $request->getAttribute('csrf_value'),
            'flashError' => $session->getSegment('deneb')->getFlash('flashError')
        ]);
    })->setName('login');

    $app->post('/auth', function($request, $response, $args) use ($session) {
      $loginDetails = $request->getParsedBody();
      if (doAuthentication($loginDetails)) {
        $segment = $session->getSegment('deneb');
        $segment->set('username', $loginDetails['username']);
        $segment->set('auth', true);
        $session->commit();

        return $response->withRedirect($this->router->pathFor('admin'), 301);
      } else {
        $segment = $session->getSegment('deneb');
        $segment->setFlash('flashError', "Authentication failed");
        $segment->set('auth', false);
        $session->commit();

        return $response->withRedirect($this->router->pathFor('login'), 301);
      }
    });

    $app->get('/admin/edit/{hash}', function($request, $response, $args) use ($session) {
        if (sessionCheck($session)) {
            $navigation = createNavigation(loadPages($this->pagePath), $this->pagePath, true);
            $reverseNavigation = createReverseNavigation(loadPages($this->pagePath));
            $page = readPage($reverseNavigation[$args['hash']]);

            $filePath = $this->uploadPath . '/' . $args['hash'];

            $segment = $session->getSegment('deneb');
            $segment->setFlash('path', $reverseNavigation[$args['hash']]);
            $flashSuccess = $segment->getFlash('flashSuccess');
            $flashError = $segment->getFlash('flashError');
            $flashWarn = $segment->getFlash('flashWarn');
            $flashInfo = $segment->getFlash('flashInfo');
            $session->commit();

            $config = new Config_Lite(CONFIG_FILE_PATH);

            $headerText = $config->get('template', 'headerText', false);
            $headerText = str_replace('{{ baseUrl }}', $request->getUri()->getBasePath(), $headerText);
            $headerText = str_replace('{{ templatePath }}', $this->templatePath, $headerText);

            $footerText = $config->get('template', 'footerText', false);

            return $this->view->render($response, 'edit.twig', [
                'hash' => $args['hash'],
                'navigation' => $navigation,
                'flashSuccess' => $flashSuccess,
                'flashError' => $flashError,
                'flashWarn' => $flashWarn,
                'flashInfo' => $flashInfo,
                'path' => substr(str_replace($this->pagePath, '', $reverseNavigation[$args['hash']]), 0, -3),
                'page' => $page,
                'files' => loadFiles($filePath),
                'baseUrl' => $request->getUri()->getBasePath(),
                'templates' => $this->pageTemplates,
                'templatePath' => $this->templatePath,
                'headerText' => $headerText,
                'footerText' => $footerText,
                'name' => $request->getAttribute('csrf_name'),
                'value' => $request->getAttribute('csrf_value')
            ]);
        } else {
            return $response->withRedirect($this->router->pathFor('login'), 301);
        }
    })->setName('edit');

    $app->post('/admin/update', function($request, $response, $args) use ($session) {
        if (sessionCheck($session)) {
            $segment = $session->getSegment('deneb');

            $currentPath = $segment->getFlash('path');

            $pageData = $request->getParsedBody();

            // check if any files were uploaded
            $files = $request->getUploadedFiles();

            $content = $pageData['content'];

            $currentHash = $pageData['hash'];

            $reverseNavigation = createReverseNavigation(loadPages($this->pagePath));

            //var_dump($reverseNavigation);

            unset($pageData['content'], $pageData['csrf_name'], $pageData['csrf_value']);

            $validationResult = pageFieldValidation($pageData);
/*
                $pageData['path'] = $this->pagePath . $pageData['path'] . '.md';
                $pageData['hash'] = hash('crc32b', $pageData['path']);

                echo($pageData['path']);
                echo($pageData['hash']);
                echo('current hash: ' . $pageData['hash']);
                deletePage($reverseNavigation[$currentHash]);
            */
            if ($validationResult == 'valid') {
                $pageData['path'] = $this->pagePath . $pageData['path'] . '.md';
                $pageData['hash'] = hash('crc32b', $pageData['path']);

                if (array_key_exists('delete', $pageData)) {
                    if ($pageData['delete'] == 'page') {

                        if (deletePage($reverseNavigation[$currentHash])) {
                            $segment->setFlash('flashSuccess', 'Page deleted successfully');

                            $filePath = $this->uploadPath . '/' . $currentHash;
                            if (!deleteDirectory($filePath)) {
                                $segment->setFlash('flashError', 'Failed to delete the page\'s upload folder completely. Check permissions?');
                            }

                            return $response->withRedirect($this->router->pathFor('admin'), 301);
                        } else {
                            $segment->setFlash('flashError', 'Failed to delete page. Check permissions?');
                            return $response->withRedirect($this->router->pathFor('edit', [
                                'hash' => $pageData['hash']
                                ]), 301);
                        }
                    } else {
                        $filePath = $this->uploadPath . '/' . $currentHash . '/' . $pageData['delete'];
                        if (deleteFile($filePath)) {
                            $segment->setFlash('flashSuccess', 'File <code>' . $pageData['delete'] .'</code> deleted successfully');
                            return $response->withRedirect($this->router->pathFor('edit', [
                                'hash' => $pageData['hash']
                                ]), 301);
                        } else {
                            $segment->setFlash('flashError', 'Unable to delete file. Check permissions?');
                            return $response->withRedirect($this->router->pathFor('edit', [
                                'hash' => $pageData['hash']
                                ]), 301);
                        }
                    }
                }

                if ($files['file']->getSize() == 0) {
                    if (array_key_exists('upload', $pageData)) {
                        $segment->setFlash('flashWarn', 'No file or zero-length object uploaded');
                    }
                } else {
                    if ($files['file']->getError() === UPLOAD_ERR_OK) {
                        $filePath = $this->uploadPath . '/' . $pageData['hash'];
                        if (createPath($filePath)) {
                            $files['file']->moveTo($filePath . '/' . $files['file']->getClientFilename());
                            $segment->setFlash('flashInfo', 'File <code>' . $files['file']->getClientFilename() . '</code> uploaded');
                        } else {
                            $segment->setFlash('flashError', 'Unable to create file path');
                        }
                    } else {
                        $segment->setFlash('flashError', $files['files']->getError());
                    }
                }

                if ($currentPath != $pageData['path']) {
                    $path = explode('/', $pageData['path']);

                    if (count($path) > 3) {
                        $segment->setFlash('createForm', $pageData);
                        $segment->setFlash('flashError', 'At the moment, deneb only supports maximum of 2 levels of nesting');
                        $session->commit();
                        return $response->withRedirect($this->router->pathFor('edit', [
                            'hash' => $currentHash
                            ]), 301);
                    } else {
                        $path = array_slice($path, 0, -1);
                        $path = implode('/', $path);

                        if (createPath($path)) {
                            writePage($pageData, $content);
                        } else {
                            $segment->setFlash('flashError', 'Unable to create file path. Possible causes: <li>An existing file/directory with the same name</li><li>Permissions not set properly</li>');
                            $session->commit();
                            return $response->withRedirect($this->router->pathFor('edit', [
                            'hash' => $currentHash
                            ]), 301);
                        }
                    }

                    if (!deletePage($currentPath)) {
                        $segment->setFlash('flashError', 'Unable to remove old file:' . $currentPath);
                        $session->commit();
                        return $response->withRedirect($this->router->pathFor('edit', [
                            'hash' => $currentHash
                        ]), 301);
                    }
                } else {
                    writePage($pageData, $content);
                }

                $segment->setFlash('flashSuccess', 'Page edited successfully');
                $session->commit();

                if (!array_key_exists('upload', $pageData)) {
                    return $response->withRedirect($this->router->pathFor('admin'), 301);
                } else {
                    return $response->withRedirect($this->router->pathFor('edit', [
                        'hash' => $pageData['hash']
                    ]), 301);
                }
            } else {
                $segment->setFlash('flashError', $validationResult);
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

            $segment = $session->getSegment('deneb');

            $pageData = $request->getParsedBody();

            // check if any files were uploaded
            $files = $request->getUploadedFiles();

            $content = $pageData['content'];

            unset($pageData['content'], $pageData['csrf_name'], $pageData['csrf_value']);

            $validationResult = pageFieldValidation($pageData);

            if ($validationResult == 'valid') {
                $pageData['path'] = $this->pagePath . $pageData['path'] . '.md';
                $pageData['hash'] = hash('crc32b', $pageData['path']);

                if ($files['file']->getSize() == 0) {
                    if (array_key_exists('upload', $pageData)) {
                        $segment->setFlash('flashWarn', 'No file or zero-length object uploaded');
                    }
                } else {
                    if ($files['file']->getError() === UPLOAD_ERR_OK) {
                        $filePath = $this->uploadPath . '/' . $pageData['hash'];
                        if (createPath($filePath)) {
                            $files['file']->moveTo($filePath . '/' . $files['file']->getClientFilename());
                            $segment->setFlash('flashInfo', 'File ' . $files['file']->getClientFilename() . ' uploaded');
                        } else {
                            $segment->setFlash('flashError', 'Unable to create file path');
                        }
                    } else {
                        $segment->setFlash('flashError', $files['files']->getError());
                    }
                }

                $path = explode('/', $pageData['path']);

                if (count($path) > 3) {
                    $segment->setFlash('createForm', $pageData);
                    $segment->setFlash('flashError', 'At the moment, deneb only supports maximum of 2 levels of nesting');
                    $session->commit();
                    return $response->withRedirect($this->router->pathFor('new'), 301);
                } else {
                    $path = array_slice($path, 0, -1);
                    $path = implode('/', $path);

                    if (createPath($path)) {
                        writePage($pageData, $content);
                    } else {
                        $segment->setFlash('flashError', 'Unable to create file path. Possible causes: <li>An existing file/directory with the same name</li><li>Permissions not set properly</li>');
                        $session->commit();
                        return $response->withRedirect($this->router->pathFor('new'), 301);
                    }
                }

                if (!array_key_exists('upload', $pageData)) {
                    $segment->setFlash('flashSuccess', 'Page created successfully');
                    $session->commit();
                    return $response->withRedirect($this->router->pathFor('admin'), 301);
                } else {
                    return $response->withRedirect($this->router->pathFor('edit', [
                        'hash' => $pageData['hash']
                    ]), 301);
                }
            } else {
                $segment->setFlash('createForm', $pageData);
                $segment->setFlash('flashError', $validationResult);

                //$session->commit();
                return $response->withRedirect($this->router->pathFor('new'), 301);
            }
        } else {
            return $response->withRedirect($this->router->pathFor('login'), 301);
        }
    });

    $app->get('/admin/new', function($request, $response, $args) use ($session) {
        if (sessionCheck($session)) {
            $navigation = createNavigation(loadPages($this->pagePath), $this->pagePath, true);

            $config = new Config_Lite(CONFIG_FILE_PATH);

            $segment = $session->getSegment('deneb');

            // if we're being redirected from a previous error, the /create
            // POST handler would've saved the form contents,
            // so we're just passing it on to the template
            $createForm = $segment->getFlash('createForm');

            $headerText = $config->get('template', 'headerText', false);
            $headerText = str_replace('{{ baseUrl }}', $request->getUri()->getBasePath(), $headerText);

            $headerText = str_replace('{{ templatePath }}', $this->templatePath, $headerText);

            $footerText = $config->get('template', 'footerText', false);

            return $this->view->render($response, 'new.twig', [
                'flashError' => $session->getSegment('deneb')->getFlash('flashError'),
                'navigation' => $navigation,
                'baseUrl' => $request->getUri()->getBasePath(),
                'templatePath' => $this->templatePath,
                'templates' => $this->pageTemplates,
                'headerText' => $headerText,
                'footerText' => $footerText,
                'createForm' => $createForm,
                'name' => $request->getAttribute('csrf_name'),
                'value' => $request->getAttribute('csrf_value')
            ]);
        } else {
            return $response->withRedirect($this->router->pathFor('login'), 301);
        }
    })->setName('new');

    $app->get('/admin/delete/{hash}', function($request, $response, $args) use ($session) {
        if (sessionCheck($session)) {
            $segment = $session->getSegment('deneb');
            $reverseNavigation = createReverseNavigation(loadPages($this->pagePath));

            if (!unlink($reverseNavigation[$args['hash']])) {
                $segment->setFlash('flashError', 'Unable to remove old file:' . $currentPath);
                $session->commit();
                return $response->withRedirect($this->router->pathFor('edit', [
                    'hash' => $pageData['hash']
                ]), 301);
            } else {
                $segment->setFlash('flashSuccess', 'Page deleted successfully');
                $session->commit();
                return $response->withRedirect($this->router->pathFor('admin'), 301);
            }
        } else {
            return $response->withRedirect($this->router->pathFor('login'), 301);
        }
    });

    $app->post('/admin/media/upload', function($request, $response, $args) {

    });

    $app->get('/listpages', function($request, $response, $args) {
        $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator(loadPages($this->pagePath)));
        $reverseNavigation = array();

        foreach ($iterator as $leafKey => $leafValue) {
            $keys = array();
            $meta = readMeta($leafValue);
            $reverseNavigation[$meta['hash']] = realpath($leafValue);
        }
        var_dump($reverseNavigation);
    });

    $app->get('/logout', function($request, $response, $args) use ($session) {
        $baseUrl = $request->getUri()->getBasePath();
        $session->destroy();

        return $response->withRedirect($baseUrl, 301);
    });

    createRoutes(loadPages($container->pagePath), $app, $container->pagePath);

} 
/**
    This else-block gets run if firstRunCheck() returns false
*/
else 
{
    $app->get('/', function($request, $response, $args) use ($session) {
        $baseUrl = $request->getUri()->getBasePath();
        $segment = $session->getSegment('deneb');

        return $this->view->render($response, 'firstrun.twig', [
            'baseUrl' => $baseUrl,
            'name' => $request->getAttribute('csrf_name'),
            'value' => $request->getAttribute('csrf_value'),
            'templatePath' => $this->templatePath,
            'pageData' => $segment->getFlash('firstRunForm'),
            'flashError' => $segment->getFlash('flashError')
        ]);
    })->setName('setup');

    $app->post('/firstRun', function($request, $response, $args) use ($session) {
        $pageData = $request->getParsedBody();
        $baseUrl = $request->getUri()->getBasePath();
        $segment = $session->getSegment('deneb');
        $validationResult = setupFieldValidation($pageData);

        if ($validationResult == 'valid') {
            configInit($pageData['configPath']);
            createAdminUser($pageData);
            $segment->set('username', $pageData['username']);
            $segment->set('auth', true);
            $session->commit();

            // set the active template
            $container['template'] = 'deneb';

            return $response->withRedirect($baseUrl . '/admin', 301);
        } else {
            $segment->setFlash('firstRunForm', $pageData);
            $segment->setFlash('flashError', $validationResult);

            return $response->withRedirect($this->router->pathFor('setup'), 301);
        }
    });
}

// Run app

$app->run();