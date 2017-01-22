<?php

require 'ImageTwig.php';

include 'configPath.php';

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
$container['template'] = 'deneb';

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
if (firstRunCheck()) {
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

            unset($pageData['content'], $pageData['csrf_name'], $pageData['csrf_value']);

            $validationResult = pageFieldValidation($pageData);

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
                    if (!is_null($pageData['upload'])) {
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

                if ($currentPath != $pageData['path']) {
                    if (!unlink($currentPath)) {
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

                if (is_null($pageData['upload'])) {
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
                    if (!is_null($pageData['upload'])) {
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

                writePage($pageData, $content);

                if (is_null($pageData['upload'])) {
                    $segment->setFlash('flashSuccess', 'Page created successfully');
                    $session->commit();
                    return $response->withRedirect($this->router->pathFor('admin'), 301);
                } else {
                return $response->withRedirect($this->router->pathFor('edit', [
                    'hash' => $pageData['hash']
                ]), 301);
                }
            } else {
                $segment->set('createForm', $pageData);
                $segment->setFlash('flashError', $validationResult);

                $session->commit();
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

} else {
    $app->get('/', function($request, $response, $args) {
        $baseUrl = $request->getUri()->getBasePath();

        return $this->view->render($response, 'firstrun.twig', [
            'baseUrl' => $baseUrl,
            'name' => $request->getAttribute('csrf_name'),
            'value' => $request->getAttribute('csrf_value'),
            'templatePath' => $this->templatePath
        ]);
    });

    $app->post('/firstRun', function($request, $response, $args) use ($session) {
        $userDetails = $request->getParsedBody();
        $baseUrl = $request->getUri()->getBasePath();
        if (createAdminUser($userDetails) && configInit()) {
            $segment = $session->getSegment('deneb');
            $segment->set('username', $userDetails['username']);
            $segment->set('auth', true);
            $session->commit();

            // set the active template
            $container['template'] = 'deneb';

            return $response->withRedirect($baseUrl . '/admin', 301);
        } else {
            return $this->view->render($response, 'error.twig', [
                'baseUrl' => $request->getUri()->getBasePath(),
                'templatePath' => $this->templatePath
            ]);
        }
    });
}

// Run app

$app->run();

function convertQuotes($text)
{
    $converted = str_replace('\"', '\'', $text);
    return $converted;
}

function pageFieldValidation($pageData)
{
    $validationResult = 'valid';

    if ($pageData['path'] == '' || $pageData['path'] == null) {
        if ($validationResult == 'valid') {
            $validationResult = '<li>Page path is a required field</li>';
        } else {
            $validationResult .= '<li>Page path is a required field</li>';
        }
    }

    if ($pageData['path'] == '/') {
        if ($validationResult == 'valid') {
            $validationResult = '<li>Page path cannot just be a /, please enter a name after it</li>';
        } else {
            $validationResult .= '<li>Page path cannot just be a /, please enter a name after it</li>';
        }
    }

    if (substr($pageData['path'], 0, 1) != '/') {
        if ($validationResult == 'valid') {
            $validationResult = '<li>Please start your paths with /</li>';
        } else {
            $validationResult .= '<li>Please start your paths with /</li>';
        }
    }

    if ($pageData['linkname'] == '' || $pageData['linkname'] == null) {
        if ($validationResult == 'valid') {
            $validationResult = '<li>Linkname is a required field</li>';
        } else {
            $validationResult .= '<li>Linkname is a required field</li>';
        }
    }

    return $validationResult;
}

function console_log($data)
{
  echo '<script>';
  echo 'console.log('. json_encode( $data ) .')';
  echo '</script>';
}

function sessionCheck($session)
{
    $segment = $session->getSegment('deneb');
    return $segment->get('auth');
}

function firstRunCheck()
{
    static $configFileExists = false;

    if (!$configFileExists) {
        if (file_exists(CONFIG_FILE_PATH)) {
            $configFileExists = true;
        }
        return $configFileExists;
    } else {
        return $configFileExists;
    }
}

function setConfig($session)
{
    $config = new Config_Lite('config.ini', LOCK_EX);

    $template = $config->get('application', 'template', false);

    if ($template == false) {
        $segment = $session->getSegment('deneb');
        $segment->set('persistentWarn', '<li>The template setting in your config.ini could not be found. Default template (\'deneb\') has been set.</li>');

        $config->set('application', 'template', 'deneb');
        $config->save();
    } else {
        $container['template'] = $template;
    }
}

function createAdminUser($userDetails)
{
    $config = new Config_Lite('config.ini');

    $config->set('admin', 'username', $userDetails['username'])
        ->set('admin', 'password', password_hash($userDetails['password'], PASSWORD_DEFAULT))
        ->set('admin', 'email', $userDetails['email'])
        ->set('admin', 'displayname', $userDetails['displayname']);

    $config->save();

    return true;
}

function configInit()
{
    $config = new Config_Lite('config.ini');

    $config->set('application', 'template', 'deneb');

    $config->save();

    return true;
}

function loadPages($path)
{
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

function loadTemplates($path = 'templates', $depth = 0)
{
    $templates = array();
    $exclude = [".DS_Store", ".", "..", "Desktop.ini", "Thumbs.db"];
    $required = ["admin.twig", "base.twig", "edit.twig", "login.twig", "new.twig", "page.twig"];

    $dir = new DirectoryIterator($path);

    if ($depth == 0) {
        foreach ($dir as $file) {
            if (!in_array($file, $exclude)) {
                if ($file->isDir()) {
                    $templates[$file->getFilename()] = loadTemplates($file->getPathname(), $depth + 1);
                }
            }
        }
    } else if ($depth == 1) {
        $dirList = array();

        foreach ($dir as $file) {
            $dirList[] = $file->getFilename();
        }

        foreach ($required as $templateFile) {
            if (!in_array($templateFile, $dirList)) {
                return false; // incomplete template, suppress path so it can't be used
            }
        }

        if (is_array($templates)) {
            $templates = $path;
        }
    }
    return $templates;
}

function loadPageTemplates($path)
{
    $templates = array();
    $exclude = [".DS_Store", ".", "..", "Desktop.ini", "Thumbs.db", "admin.twig", "base.twig", "edit.twig", "login.twig", "new.twig", "firstrun.twig"];

    $dir = new DirectoryIterator($path);

    foreach ($dir as $file) {
        if (!in_array($file, $exclude)) {
            if ($file->isFile()) {
                if ($file == 'page.twig') {
                    $templates['default'] = $file->getFilename();
                } else {
                    $templates[substr($file, 0, -5)] = $file->getFilename;
                }
            }
        }
    }
    return $templates;
}

function loadFiles($path)
{
    $files = array();
    $exclude = [".DS_Store", ".", "..", "Desktop.ini", "Thumbs.db"];

    // check if $path is a directory first
    // because we aren't creating one if no files were uploaded
    if (is_dir($path)) {
        foreach(scandir($path) as $file) {
            if (!in_array($file, $exclude)) {
                $files[$file] = $path . '/' . $file;
            }
        }
    }
    return $files;
}

function createRoutes($pages, $app, $pagePath)
{
    // we need to use the container's pagePath values for createNavigation()
    // because this function is going to be called recursively
    $navigation = createNavigation(loadPages($app->getContainer()->pagePath), $app->getContainer()->pagePath);

    $config = new Config_Lite(CONFIG_FILE_PATH);

    $headerText = $config->get('template', 'headerText', false);
    $headerText = str_replace('{{ templatePath }}', $app->getContainer()->templatePath, $headerText);

    $footerText = $config->get('template', 'footerText', false);

    $javascriptSnippet = $config->get('template', 'javascriptSnippet', false);

    $javascriptSnippet = str_replace('{{ templatePath }}', $app->getContainer()->templatePath, $javascriptSnippet);
    foreach ($pages as $pageName => $path) {
        if (!is_array($path)) {
            if ($path == 'pages/index.md') {
                $app->get('/', function($request, $response, $args) use ($path, $navigation) {
                    $page = readPage($path);
                    $filePath = $this->uploadPath . '/' . $page['meta']['hash'];
                    return $this->view->render($response, $this->pageTemplates[$page['meta']['template']], [
                        'baseUrl' => $request->getUri()->getBasePath(),
                        'templatePath' => $this->templatePath,
                        'navigation' => $navigation,
                        'meta' => $page['meta'],
                        'files' => loadFiles($filePath),
                        'contents' => $page['contents']
                        ]);
                })->setName('index');

                $app->get('/index', function($request, $response, $args) use ($path, $navigation) {
                        return $response->withRedirect($this->router->pathFor('index'), 301);
                });
            } else {
                $app->get(substr($path, 5, -3), function($request, $response, $args) use ($path, $navigation) {
                    $page = readPage($path);
                    $filePath = $this->uploadPath . '/' . $page['meta']['hash'];
                    return $this->view->render($response, $this->pageTemplates[$page['meta']['template']], [
                        'templatePath' => $this->templatePath,
                        'baseUrl' => $request->getUri()->getBasePath(),
                        'navigation' => $navigation,
                        'meta' => $page['meta'],
                        'files' => loadFiles($filePath),
                        'contents' => $page['contents']
                    ]);
                });
            }
        } else {
            createRoutes($path, $app, $pagePath);
        }
    }
}
/*
function createSiteNavigation($pages)
{
    $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($pages));
    $navigation = array();

    foreach ($iterator as $leafKey => $leafValue) {
        $keys = array();
        $meta = readMeta($leafValue);

        $path = '/'; // we start off with the root

        if (!array_key_exists($meta['category'], $navigation)) {
            $navigation[$meta['category']] = array();
        }

        if ($iterator->getDepth() == 0) {
            $navigation[$meta['category']][] = $meta['linkname'] . '\\' . $path;
        } else {
            foreach (range(0, $iterator->getDepth() - 1) as $depth) {
                $keys[] = $iterator->getSubIterator($depth)->key();
            }

            $path = '/'.join('/', $keys);
            $navigation[$meta['category']][] = $meta['linkname'] . '\\' . $path;
        }
    }
    return $navigation;
}
*/
function createNavigation($pages, $pagePath, $index = false)
{
    $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($pages));
    $navigation = array();

    foreach ($iterator as $leafKey => $leafValue) {
        $keys = array();
        $meta = readMeta($leafValue);

        // exclude the index page, we don't need a link for that (site logo performs the same function)
        if ($index || $meta['linkname'] != 'Home') {
            if ($iterator->getDepth() == 0) {
                $navigation[$meta['linkname']] = $meta['category'] . '\\' . $meta['hash'] . '\\' . substr($leafValue, strlen($pagePath), -3); // '\\' is being used as a delimiter between the category and hash
            } else {
                foreach (range(0, $iterator->getDepth() - 1) as $depth) {
                    $keys[] = $iterator->getSubIterator($depth)->key();
                }

                $path = ''.join('/', $keys);

                if (!array_key_exists($path, $navigation)) {
                    $navigation[$path] = array();
                }
                $navigation[$path][$meta['linkname']] = $meta['category']. '\\' . $meta['hash'] . '\\' . substr($leafValue, strlen($pagePath), -3);
            }
        }
    }

    // sort the navigation array by key alphabetically before returning
    ksort($navigation);

    return $navigation;
}

function createReverseNavigation($pages)
{
    $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($pages));
    $reverseNavigation = array();

    foreach ($iterator as $leafKey => $leafValue) {
        $keys = array();
        $meta = readMeta($leafValue);
        $reverseNavigation[$meta['hash']] = $leafValue;
    }
    return $reverseNavigation;
}

function writePage($pageData, $content)
{
    $file = fopen($pageData['path'], 'w');
    array_shift($pageData); // first element is path, which we do not need
    $pageData = json_encode($pageData, JSON_PRETTY_PRINT);
    $content = trim($content, " \r\n\t\0"); // trim extraneous characters from content
    $page = $pageData . "\r\n\r\n--\r\n" . $content;
    fwrite($file, $page);
    fclose($file);
}

function readMeta($path)
{
    $fileContents = file_get_contents($path);
    $contents = explode("--", $fileContents);
    $pageMeta = json_decode(array_shift($contents), true);

    if (empty($pageMeta['category'])) {
        $pageMeta['category'] = "Uncategorised";
    }
    return $pageMeta;
}

function readPage($path)
{
    $page = array();
    $fileContents = file_get_contents($path);
    $contents = explode("--", $fileContents);
    $page['meta'] = json_decode(array_shift($contents), true);
    $page['contents'] = implode($contents);
    return $page;
}

function doAuthentication($loginDetails)
{
  $config = new Config_Lite(CONFIG_FILE_PATH);
  if (($loginDetails['username'] == $config->get('admin', 'username')) && password_verify($loginDetails['password'], $config->get('admin', 'password'))) {
    return true;
  }
  return false;
}

function createPath($path)
{
    // check if path exists and create if it doesn't
    if (!file_exists($path)) {
        return mkdir($path, 0777, true);
    } else {
        if (!is_dir($path)) {
            return false;
        }
    }
    return true;
}

function deleteFile($path)
{
    
}
