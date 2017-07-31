<?php

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

function configInit($path)
{
    global $container;

    $path = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'config.ini';

    $fp = fopen('configPath.php', 'w');

    $configPathFileString = '<?php
        define(\'CONFIG_FILE_PATH\', \'' . $path . '\');
?>';

    // we need to explicity define CONFIG_FILE_PATH here anyway
    // because the configPath.php would have already been included with old/empty data
    define('CONFIG_FILE_PATH', $path);

    fwrite($fp, $configPathFileString);
    fclose($fp);

    $config = new Config_Lite($path);

    $config->set('application', 'template', 'deneb');

    $config->set('template', 'headerText', "<li><a class='borderless-grid' href='{{ baseUrl }}'> <img class='deneb-logo-nav' src='{{ templatePath }}/assets/img/deneb-logo.svg' alt='deneb' /></a></li>");

    $config->set('template', 'footerText', "<p class='align-center'>Powered by <a href='#'>deneb</a> | Distributed under the <a href='#'>Apache 2.0</a> license</p>");

    $config->save();

    return true;
}

function setConfig($session)
{
    $config = new Config_Lite(CONFIG_FILE_PATH, LOCK_EX);

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
    $config = new Config_Lite(CONFIG_FILE_PATH);

    $config->set('admin', 'username', $userDetails['username'])
        ->set('admin', 'password', password_hash($userDetails['password'], PASSWORD_DEFAULT))
        ->set('admin', 'email', $userDetails['email'])
        ->set('admin', 'displayName', $userDetails['displayName']);

    $config->save();

    return true;
}

function setupFieldValidation($pageData)
{
    $validationResult = 'valid';

    if (in_array('', $pageData)) {
        if ($validationResult == 'valid') {
            $validationResult = '<li>All fields are required</li>';
        } else {
            $validationResult .= '<li>All fields are required</li>';
        }
    }

    if ($pageData['password'] != $pageData['confirm']) {
        if ($validatioResult == 'valid') {
            $validationResult = '<li>Passwords do not match</li>';
        } else {
            $validationResult .= '<li>Passwords do not match</li>';
        }
    }

    if (strpos($pageData['configPath'], $_SERVER['DOCUMENT_ROOT']) !== false) {
        if ($validationResult == 'valid') {
            $validationResult = '<li>Configuration file path cannot be in the document root</li>';
        } else {
            $validationResult .= '<li>Configuration file path cannot be in the document root</li>';
        }
    } else {
        if (!is_writable($pageData['configPath'])) {
            if ($validationResult == 'valid') {
                $validationResult = '<li>Configuration file path is not writable, please set permissions</li>';
            } else {
                $validationResult .= '<li>Configuration file path is not writable, please set permissions</li>';
            }
        }
    }

    return $validationResult;
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
                    $templates[substr($file, 0, -5)] = $file->getFilename();
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
                $app->get('/', function($request, $response, $args) use ($path, $navigation, $headerText, $footerText, $javascriptSnippet) {
                    $page = readPage($path);
                    $filePath = $this->uploadPath . '/' . $page['meta']['hash'];
                    $headerText = str_replace('{{ baseUrl }}', $request->getUri()->getBasePath(), $headerText);
                    $javascriptSnippet = str_replace('{{ baseUrl }}', $request->getUri()->getBasePath(), $javascriptSnippet);

                    return $this->view->render($response, $this->pageTemplates[$page['meta']['template']], [
                        'baseUrl' => $request->getUri()->getBasePath(),
                        'templatePath' => $this->templatePath,
                        'navigation' => $navigation,
                        'meta' => $page['meta'],
                        'files' => loadFiles($filePath),
                        'headerText' => $headerText,
                        'footerText' => $footerText,
                        'javascriptSnippet' => $javascriptSnippet,
                        'contents' => $page['contents']
                        ]);
                })->setName('index');

                $app->get('/index', function($request, $response, $args) use ($path, $navigation) {
                        return $response->withRedirect($this->router->pathFor('index'), 301);
                });
            } else {
                $app->get(substr($path, 5, -3), function($request, $response, $args) use ($path, $navigation, $headerText, $footerText) {
                    $page = readPage($path);
                    $filePath = $this->uploadPath . '/' . $page['meta']['hash'];
                    $headerText = str_replace('{{ baseUrl }}', $request->getUri()->getBasePath(), $headerText);
                    return $this->view->render($response, $this->pageTemplates[$page['meta']['template']], [
                        'templatePath' => $this->templatePath,
                        'baseUrl' => $request->getUri()->getBasePath(),
                        'navigation' => $navigation,
                        'meta' => $page['meta'],
                        'files' => loadFiles($filePath),
                        'headerText' => $headerText,
                        'footerText' => $footerText,
                        'javascriptSnippet' => $javascriptSnippet,
                        'contents' => $page['contents']
                    ]);
                });
            }
        } else {
            createRoutes($path, $app, $pagePath);
        }
    }
}

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
        return mkdir($path, 0755, true);
    } else {
        if (!is_dir($path)) {
            return false;
        }
    }
    return true;
}

function deletePage($path)
{
    $result = true;
    $result = unlink($path);

    $path = explode('/', $path);
    $path = array_slice($path, 0, -1);
    $path = implode('/', $path);

    $iterator = new \FilesystemIterator($path);

    $dirEmpty = !$iterator->valid();

    if ($dirEmpty) {
        // if previous step failed, a file must exist in the dir, so
        // this block would not execute, preserving the result anyway
        $result = rmdir($path);
    }

    return $result;
}

function deleteFile($path)
{
    return unlink($path);
}

function deleteDirectory($path)
{
    $result = true;

    foreach (glob($path.'/*.*') as $filename) {
        if (is_file($filename)) {
            $result = unlink($filename);
        }
    }

    $iterator = new \FilesystemIterator($path);

    $dirEmpty = !$iterator->valid();

    if ($dirEmpty) {
        $result = rmdir($path);
    }

    return $result;
}
