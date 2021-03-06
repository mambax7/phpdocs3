<?php

use XoopsModules\Protector\Registry;

require __DIR__ . '/preloads/autoloader.php';

// start hack by Trabis
if (!class_exists('XoopsModules\Protector\Registry')) {
    exit('Registry not found');
}

$registry  = Registry::getInstance();
$mydirname = $registry->getEntry('mydirname');
$mydirpath = $registry->getEntry('mydirpath');
$language  = $registry->getEntry('language');
// end hack by Trabis

global $xoopsUser;

require XOOPS_ROOT_PATH . '/include/cp_functions.php';

$mytrustdirname = basename(__DIR__);
$mytrustdirpath = __DIR__;

// environment
require_once XOOPS_ROOT_PATH . '/class/template.php';
/** @var \XoopsModuleHandler $moduleHandler */
$moduleHandler    = xoops_getHandler('module');
$xoopsModule       = $moduleHandler->getByDirname($mydirname);
/** @var \XoopsConfigHandler $configHandler */
$configHandler    = xoops_getHandler('config');
$xoopsModuleConfig = $configHandler->getConfigsByCat(0, $xoopsModule->getVar('mid'));

// check permission of 'module_admin' of this module
/** @var \XoopsGroupPermHandler $modulepermHandler */
$modulepermHandler = xoops_getHandler('groupperm');
if (!is_object(@$xoopsUser) || !$modulepermHandler->checkRight('module_admin', $xoopsModule->getVar('mid'), $xoopsUser->getGroups())) {
    die('only admin can access this area');
}

$xoopsOption['pagetype'] = 'admin';
//require XOOPS_ROOT_PATH . '/include/cp_functions.php';

// language files (admin.php)
//$language = empty( $xoopsConfig['language'] ) ? 'english' : $xoopsConfig['language'] ;  //hack by Trabis
if (file_exists("$mydirpath/language/$language/admin.php")) {
    // user customized language file
    require_once "$mydirpath/language/$language/admin.php";
} elseif (file_exists("$mytrustdirpath/language/$language/admin.php")) {
    // default language file
    require_once "$mytrustdirpath/language/$language/admin.php";
} else {
    // fallback english
    require_once "$mytrustdirpath/language/english/admin.php";
}

// language files (main.php)
//$language = empty( $xoopsConfig['language'] ) ? 'english' : $xoopsConfig['language'] ;  //hack by Trabis
if (file_exists("$mydirpath/language/$language/main.php")) {
    // user customized language file
    require_once "$mydirpath/language/$language/main.php";
} elseif (file_exists("$mytrustdirpath/language/$language/main.php")) {
    // default language file
    require_once "$mytrustdirpath/language/$language/main.php";
} else {
    // fallback english
    require_once "$mytrustdirpath/language/english/main.php";
}

if (!empty($_GET['lib'])) {
    // common libs (eg. altsys)
    $lib  = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['lib']);
    $page = preg_replace('/[^a-zA-Z0-9_-]/', '', @$_GET['page']);

    if (file_exists(XOOPS_TRUST_PATH . '/libs/' . $lib . '/' . $page . '.php')) {
        include XOOPS_TRUST_PATH . '/libs/' . $lib . '/' . $page . '.php';
    } elseif (file_exists(XOOPS_TRUST_PATH . '/libs/' . $lib . '/index.php')) {
        include XOOPS_TRUST_PATH . '/libs/' . $lib . '/index.php';
    } else {
        die('wrong request');
    }
} else {
    // fork each pages of this module
    $page = preg_replace('/[^a-zA-Z0-9_-]/', '', @$_GET['page']);

    if (file_exists("$mytrustdirpath/admin/$page.php")) {
        include "$mytrustdirpath/admin/$page.php";
    } elseif (file_exists("$mytrustdirpath/admin/index.php")) {
        include "$mytrustdirpath/admin/index.php";
    } else {
        die('wrong request');
    }
}
