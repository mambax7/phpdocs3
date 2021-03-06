<?php namespace XoopsModules\Protector;

/**
 * Class Guardian
 */
class Guardian
{
    /**
     * @var string
     */
    public $mydirname;
    /**
     * @var \mysqli
     */
    public $_conn;
    /**
     * @var array
     */
    public $_conf = array(); //mb TODO
    /**
     * @var string
     */
    public $_conf_serialized = ''; //mb TODO could be also false
    /**
     * @var array
     */
    public $_bad_globals = array();
    /**
     * @var string
     */
    public $message                = '';
    /**
     * @var bool
     */
    public $warning                = false;
    /**
     * @var bool
     */
    public $error                  = false;
    /**
     * @var array
     */
    public $_doubtful_requests     = array();
    /**
     * @var array
     */
    public $_bigumbrella_doubtfuls = array();
    /**
     * @var array
     */
    public $_dblayertrap_doubtfuls        = array();
    /**
     * @var array
     */
    public $_dblayertrap_doubtful_needles = array(
        'information_schema',
        'select',
        "'",
        '"',
    );
    /**
     * @var bool
     */
    public $_logged = false;
    /**
     * @var bool
     */
    public $_done_badext   = false;
    /**
     * @var bool
     */
    public $_done_intval   = false;
    /**
     * @var bool
     */
    public $_done_dotdot   = false;
    /**
     * @var bool
     */
    public $_done_nullbyte = false;
    /**
     * @var bool
     */
    public $_done_contami  = false;
    /**
     * @var bool
     */
    public $_done_isocom   = false;
    /**
     * @var bool
     */
    public $_done_union    = false;
    /**
     * @var bool
     */
    public $_done_dos      = false;
    /**
     * @var bool
     */
    public $_safe_badext  = true;
    /**
     * @var bool
     */
    public $_safe_contami = true;
    /**
     * @var bool
     */
    public $_safe_isocom  = true;
    /**
     * @var bool
     */
    public $_safe_union   = true;
    /**
     * @var int
     */
    public $_spamcount_uri = 0;
    /**
     * @var bool
     */
    public $_should_be_banned_time0 = false;
    /**
     * @var bool
     */
    public $_should_be_banned       = false;
    /**
     * @var string
     */
    public $_dos_stage; //mb TODO is not used anywhere
    /**
     * @var string|null
     */
    public $ip_matched_info; //mb TODO could be also null or int
    /**
     * @var string
     */
    public $last_error_type = 'UNKNOWN';

    /**
     * Constructor
     */
    protected function __construct()
    {
        $this->mydirname = 'protector';

        // Preferences from configs/cache
        $this->_conf_serialized = @file_get_contents($this->get_filepath4confighcache());
        $this->_conf            = @unserialize($this->_conf_serialized);
        if (empty($this->_conf)) {
            $this->_conf = array();
        }

        if (!empty($this->_conf['global_disabled'])) {
            return;
        }

        // die if PHP_SELF XSS found (disabled in 2.53)
        //    if ( preg_match( '/[<>\'";\n ]/' , @$_SERVER['PHP_SELF'] ) ) {
        //        $this->message .= "Invalid PHP_SELF '{$_SERVER['PHP_SELF']}' found.\n" ;
        //        $this->output_log( 'PHP_SELF XSS' ) ;
        //        die( 'invalid PHP_SELF' ) ;
        //    }

        // sanitize against PHP_SELF/PATH_INFO XSS (disabled in 3.33)
        //    $_SERVER['PHP_SELF'] = strtr( @$_SERVER['PHP_SELF'] , array( '<' => '%3C' , '>' => '%3E' , "'" => '%27' , '"' => '%22' ) ) ;
        //    if( ! empty( $_SERVER['PATH_INFO'] ) ) $_SERVER['PATH_INFO'] = strtr( @$_SERVER['PATH_INFO'] , array( '<' => '%3C' , '>' => '%3E' , "'" => '%27' , '"' => '%22' ) ) ;

        $this->_bad_globals = array(
            'GLOBALS',
            '_SESSION',
            'HTTP_SESSION_VARS',
            '_GET',
            'HTTP_GET_VARS',
            '_POST',
            'HTTP_POST_VARS',
            '_COOKIE',
            'HTTP_COOKIE_VARS',
            '_SERVER',
            'HTTP_SERVER_VARS',
            '_REQUEST',
            '_ENV',
            '_FILES',
            'xoopsDB',
            'xoopsUser',
            'xoopsUserId',
            'xoopsUserGroups',
            'xoopsUserIsAdmin',
            'xoopsConfig',
            'xoopsOption',
            'xoopsModule',
            'xoopsModuleConfig',
        );

        $this->_initial_recursive($_GET, 'G');
        $this->_initial_recursive($_POST, 'P');
        $this->_initial_recursive($_COOKIE, 'C');
    }

    /**
     * @param array|string $val
     * @param string       $key
     *
     * @return void
     */
    protected function _initial_recursive($val, $key)
    {
        if (is_array($val)) {
            foreach ($val as $subkey => $subval) {
                // check bad globals
                if (in_array($subkey, $this->_bad_globals, true)) {
                    $this->message .= "Attempt to inject '$subkey' was found.\n";
                    $this->_safe_contami   = false;
                    $this->last_error_type = 'CONTAMI';
                }
                $this->_initial_recursive($subval, $key . '_' . base64_encode($subkey));
            }
        } else {
            // check nullbyte attack
            if (@$this->_conf['san_nullbyte'] && false !== strpos($val, chr(0))) {
                $val = str_replace(chr(0), ' ', $val);
                $this->replace_doubtful($key, $val);
                $this->message .= "Injecting Null-byte '$val' found.\n";
                $this->output_log('NullByte', 0, false, 32);
                // $this->purge() ;
            }

            // register as doubtful requests against SQL Injections
            if (preg_match('?[\s\'"`/]?', $val)) {
                $this->_doubtful_requests["$key"] = $val;
            }
        }
    }

    /**
     * @return Guardian
     */
    public static function getInstance()
    {
        static $instance;
        if (!isset($instance)) {
            $instance = new Guardian();
        }

        return $instance;
    }

    /**
     * @return bool
     */
    public function updateConfFromDb()
    {
        $constpref = '_MI_' . strtoupper($this->mydirname);

        if (null === ($this->_conn)) {
            return false;
        }

        $result = @mysqli_query($this->_conn, 'SELECT conf_name,conf_value FROM ' . XOOPS_DB_PREFIX . "_config WHERE conf_title like '" . $constpref . "%'");
        if (!$result || $GLOBALS['xoopsDB']->getRowsNum($result) < 5) {
            return false;
        }
        $db_conf = array();
        while (list($key, $val) = $GLOBALS['xoopsDB']->fetchRow($result)) {
            $db_conf[$key] = $val;
        }
        $db_conf_serialized = serialize($db_conf);

        // update config cache
        if ($db_conf_serialized != $this->_conf_serialized) {
            $fp = fopen($this->get_filepath4confighcache(), 'wb');
            fwrite($fp, $db_conf_serialized);
            fclose($fp);
            $this->_conf = $db_conf;
        }

        return true;
    }

    /**
     * @param \mysqli $conn
     *
     * @return void
     */
    public function setConn($conn)
    {
        $this->_conn = $conn;
    }

    /**
     * @return array
     */
    public function getConf()
    {
        return $this->_conf;
    }

    /**
     * @param bool $redirect_to_top
     *
     * @return void
     */
    public function purge($redirect_to_top = false)
    {
        $this->purgeNoExit();

        if ($redirect_to_top) {
            header('Location: ' . XOOPS_URL . '/');
            exit;
        } else {
            $ret = $this->call_filter('PrepurgeExit');
            if (false === $ret) {
                die('Protector detects attacking actions');
            }
        }
    }

    /**
     * @return void
     */
    public function purgeSession()
    {
        // clear all session values
        if (isset($_SESSION)) {
            foreach ($_SESSION as $key => $val) {
                $_SESSION[$key] = '';
                if (isset($GLOBALS[$key])) {
                    $GLOBALS[$key] = '';
                }
            }
        }
    }

    /**
     * @return void
     */
    public function purgeCookies()
    {
        if (!headers_sent()) {
            $domain =  defined(XOOPS_COOKIE_DOMAIN) ? XOOPS_COOKIE_DOMAIN : '';
            $past = time() - 3600;
            foreach ($_COOKIE as $key => $value) {
                setcookie($key, '', $past, '', $domain);
                setcookie($key, '', $past, '/', $domain);
            }
        }
    }

    /**
     * @return void
     */
    public function purgeNoExit()
    {
        $this->purgeSession();
        $this->purgeCookies();
    }

    /**
     * @return void
     */
    public function deactivateCurrentUser()
    {
        /** @var \XoopsUser|\XoopsObject $xoopsUser */
        global $xoopsUser;

        if (is_object($xoopsUser)) {
            /** @var \XoopsUserHandler */
            $userHandler = xoops_getHandler('user');
            $xoopsUser->setVar('level', 0);
            $actkey = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
            $xoopsUser->setVar('actkey', $actkey);
            $userHandler->insert($xoopsUser);
        }
        $this->purgeNoExit();
    }

    /**
     * @param string $type
     * @param int    $uid
     * @param bool   $unique_check
     * @param int    $level
     *
     * @return bool
     */
    public function output_log($type = 'UNKNOWN', $uid = 0, $unique_check = false, $level = 1)
    {
        if ($this->_logged) {
            return true;
        }

        if (!($this->_conf['log_level'] & $level)) {
            return true;
        }

        if (null === ($this->_conn)) {
            mysqli_report(MYSQLI_REPORT_OFF);
            $this->_conn = new \mysqli(XOOPS_DB_HOST, XOOPS_DB_USER, XOOPS_DB_PASS);
            if (0 !== $this->_conn->connect_errno) {
                die('db connection failed.');
            }
            if (!mysqli_select_db($this->_conn, XOOPS_DB_NAME)) {
                die('db selection failed.');
            }
        }

        $ip    = \Xmf\IPAddress::fromRequest()
                               ->asReadable();
        $agent = @$_SERVER['HTTP_USER_AGENT'];

        if ($unique_check) {
            $result = mysqli_query($this->_conn, 'SELECT ip,type FROM ' . XOOPS_DB_PREFIX . '_' . $this->mydirname . '_log ORDER BY timestamp DESC LIMIT 1');
            list($last_ip, $last_type) = $GLOBALS['xoopsDB']->fetchRow($result);
            if ($last_ip == $ip && $last_type == $type) {
                $this->_logged = true;

                return true;
            }
        }

        mysqli_query(
            $this->_conn,
            'INSERT INTO ' . XOOPS_DB_PREFIX . '_' . $this->mydirname . "_log SET ip='"
            . $GLOBALS['xoopsDB']->escape($this->_conn, $ip) . "',agent='"
            . $GLOBALS['xoopsDB']->escape($this->_conn, $agent) . "',type='"
            . $GLOBALS['xoopsDB']->escape($this->_conn, $type) . "',description='"
            . $GLOBALS['xoopsDB']->escape($this->_conn, $this->message) . "',uid='"
            . (int)$uid . "',timestamp=NOW()"
        );
        $this->_logged = true;

        return true;
    }

    /**
     * @param int $expire
     *
     * @return bool
     */
    public function write_file_bwlimit($expire)
    {
        $expire = min((int)$expire, time() + 300);

        $fp = @fopen($this->get_filepath4bwlimit(), 'wb');
        if ($fp) {
            @flock($fp, LOCK_EX);
            fwrite($fp, $expire . "\n");
            @flock($fp, LOCK_UN);
            fclose($fp);

            return true;
        } else {
            return false;
        }
    }

    /**
     * @return mixed
     */
    public function get_bwlimit()
    {
        list($expire) = @file(Guardian::get_filepath4bwlimit());
        $expire = min((int)$expire, time() + 300);

        return $expire;
    }

    /**
     * @return string
     */
    public static function get_filepath4bwlimit()
    {
        return XOOPS_VAR_PATH . '/protector/bwlimit' . substr(md5(XOOPS_ROOT_PATH . XOOPS_DB_USER . XOOPS_DB_PREFIX), 0, 6);
    }

    /**
     * @param array $bad_ips
     *
     * @return bool
     */
    public function write_file_badips($bad_ips)
    {
        asort($bad_ips);

        $fp = @fopen($this->get_filepath4badips(), 'wb');
        if ($fp) {
            @flock($fp, LOCK_EX);
            fwrite($fp, serialize($bad_ips) . "\n");
            @flock($fp, LOCK_UN);
            fclose($fp);

            return true;
        } else {
            return false;
        }
    }

    /**
     * @param int  $jailed_time
     * @param string|null|false $ip
     *
     * @return bool
     */
    public function register_bad_ips($jailed_time = 0, $ip = null)
    {
        if (empty($ip)) {
            $ip = \Xmf\IPAddress::fromRequest()
                                ->asReadable();
        }
        if (empty($ip)) {
            return false;
        }

        $bad_ips      = $this->get_bad_ips(true);
        $bad_ips[$ip] = $jailed_time ?: 0x7fffffff;

        return $this->write_file_badips($bad_ips);
    }

    /**
     * @param bool $with_jailed_time
     *
     * @return array
     */
    public function get_bad_ips($with_jailed_time = false)
    {
        list($bad_ips_serialized) = @file(Guardian::get_filepath4badips());
        $bad_ips = empty($bad_ips_serialized) ? array() : @unserialize($bad_ips_serialized);
        if (!is_array($bad_ips) || isset($bad_ips[0])) {
            $bad_ips = array();
        }

        // expire jailed_time
        $pos = 0;
        foreach ($bad_ips as $bad_ip => $jailed_time) {
            if ($jailed_time >= time()) {
                break;
            }
            ++$pos;
        }
        $bad_ips = array_slice($bad_ips, $pos);

        if ($with_jailed_time) {
            return $bad_ips;
        } else {
            return array_keys($bad_ips);
        }
    }

    /**
     * @return string
     */
    public static function get_filepath4badips()
    {
        return XOOPS_VAR_PATH . '/protector/badips' . substr(md5(XOOPS_ROOT_PATH . XOOPS_DB_USER . XOOPS_DB_PREFIX), 0, 6);
    }

    /**
     * @param bool $with_info
     *
     * @return array
     */
    public function get_group1_ips($with_info = false)
    {
        list($group1_ips_serialized) = @file(Guardian::get_filepath4group1ips());
        $group1_ips = empty($group1_ips_serialized) ? array() : @unserialize($group1_ips_serialized);
        if (!is_array($group1_ips)) {
            $group1_ips = array();
        }

        if ($with_info) {
            $group1_ips = array_flip($group1_ips);
        }

        return $group1_ips;
    }

    /**
     * @return string
     */
    public static function get_filepath4group1ips()
    {
        return XOOPS_VAR_PATH . '/protector/group1ips' . substr(md5(XOOPS_ROOT_PATH . XOOPS_DB_USER . XOOPS_DB_PREFIX), 0, 6);
    }

    /**
     * @return string
     */
    public function get_filepath4confighcache()
    {
        return XOOPS_VAR_PATH . '/protector/configcache' . substr(md5(XOOPS_ROOT_PATH . XOOPS_DB_USER . XOOPS_DB_PREFIX), 0, 6);
    }

    /**
     * @param array $ips
     *
     * @return bool
     */
    public function ip_match($ips)
    {
        $requestIp = \Xmf\IPAddress::fromRequest()
                                   ->asReadable();
        if (false === $requestIp) { // nothing to match
            $this->ip_matched_info = null;
            return false;
        }
        foreach ($ips as $ip => $info) {
            if ($ip) {
                switch (strtolower(substr($ip, -1))) {
                    case '.' :
                    case ':' :
                        // foward match
                        if (substr($requestIp, 0, strlen($ip)) == $ip) {
                            $this->ip_matched_info = $info;
                            return true;
                        }
                        break;
                    case '0' :
                    case '1' :
                    case '2' :
                    case '3' :
                    case '4' :
                    case '5' :
                    case '6' :
                    case '7' :
                    case '8' :
                    case '9' :
                    case 'a' :
                    case 'b' :
                    case 'c' :
                    case 'd' :
                    case 'e' :
                    case 'f' :
                        // full match
                        if ($requestIp == $ip) {
                            $this->ip_matched_info = $info;
                            return true;
                        }
                        break;
                    default :
                        // perl regex
                        if (@preg_match($ip, $requestIp)) {
                            $this->ip_matched_info = $info;
                            return true;
                        }
                        break;
                }
            }
        }
        $this->ip_matched_info = null;
        return false;
    }

    /**
     * @param string|null|false $ip
     *
     * @return bool
     */
    public function deny_by_htaccess($ip = null)
    {
        if (empty($ip)) {
            $ip = \Xmf\IPAddress::fromRequest()
                                ->asReadable();
        }
        if (empty($ip)) {
            return false;
        }
        if (!function_exists('file_get_contents')) {
            return false;
        }

        $target_htaccess = XOOPS_ROOT_PATH . '/.htaccess';
        $backup_htaccess = XOOPS_ROOT_PATH . '/uploads/.htaccess.bak';

        $ht_body = file_get_contents($target_htaccess);

        // make backup as uploads/.htaccess.bak automatically
        if ($ht_body && !is_file($backup_htaccess)) {
            $fw = fopen($backup_htaccess, 'wb');
            fwrite($fw, $ht_body);
            fclose($fw);
        }

        // if .htaccess is broken, restore from backup
        if (!$ht_body && is_file($backup_htaccess)) {
            $ht_body = file_get_contents($backup_htaccess);
        }

        // new .htaccess
        if (false === $ht_body) {
            $ht_body = '';
        }

        if (preg_match("/^(.*)#PROTECTOR#\s+(DENY FROM .*)\n#PROTECTOR#\n(.*)$/si", $ht_body, $regs)) {
            if (substr($regs[2], -strlen($ip)) == $ip) {
                return true;
            }
            $new_ht_body = $regs[1] . "#PROTECTOR#\n" . $regs[2] . " $ip\n#PROTECTOR#\n" . $regs[3];
        } else {
            $new_ht_body = "#PROTECTOR#\nDENY FROM $ip\n#PROTECTOR#\n" . $ht_body;
        }

        // error_log( "$new_ht_body\n" , 3 , "/tmp/error_log" ) ;

        $fw = fopen($target_htaccess, 'wb');
        @flock($fw, LOCK_EX);
        fwrite($fw, $new_ht_body);
        @flock($fw, LOCK_UN);
        fclose($fw);

        return true;
    }

    /**
     * @return array
     */
    public function getDblayertrapDoubtfuls()
    {
        return $this->_dblayertrap_doubtfuls;
    }

    /**
     * @param array|string $val
     * @return null|void
     */
    protected function _dblayertrap_check_recursive($val)
    {
        if (is_array($val)) {
            foreach ($val as $subval) {
                $this->_dblayertrap_check_recursive($subval);
            }
        } else {
            if (strlen($val) < 6) {
                return null;
            }
            $val = @get_magic_quotes_gpc() ? stripslashes($val) : $val;
            foreach ($this->_dblayertrap_doubtful_needles as $needle) {
                if (false !== stripos($val, $needle)) {
                    $this->_dblayertrap_doubtfuls[] = $val;
                }
            }
        }
    }

    /**
     * @param  bool $force_override
     *
     * @return void
     */
    public function dblayertrap_init($force_override = false)
    {
        if (!empty($GLOBALS['xoopsOption']['nocommon']) || defined('_LEGACY_PREVENT_EXEC_COMMON_') || defined('_LEGACY_PREVENT_LOAD_CORE_')) {
//            return null;
        } // skip

        $this->_dblayertrap_doubtfuls = array();
        $this->_dblayertrap_check_recursive($_GET);
        $this->_dblayertrap_check_recursive($_POST);
        $this->_dblayertrap_check_recursive($_COOKIE);
        if (empty($this->_conf['dblayertrap_wo_server'])) {
            $this->_dblayertrap_check_recursive($_SERVER);
        }

        if (!empty($this->_dblayertrap_doubtfuls) || $force_override) {
            @define('XOOPS_DB_ALTERNATIVE', 'ProtectorMysqlDatabase');
//            require_once dirname(__DIR__) . '/class/ProtectorMysqlDatabase.class.php';
        }
    }

    /**
     * @param array|string $val
     *
     * @return void
     */
    protected function _bigumbrella_check_recursive($val)
    {
        if (is_array($val)) {
            foreach ($val as $subval) {
                $this->_bigumbrella_check_recursive($subval);
            }
        } else {
            if (preg_match('/[<\'"].{15}/s', $val, $regs)) {
                $this->_bigumbrella_doubtfuls[] = $regs[0];
            }
        }
    }

    /**
     * @return void
     */
    public function bigumbrella_init()
    {
        $this->_bigumbrella_doubtfuls = array();
        $this->_bigumbrella_check_recursive($_GET);
        $this->_bigumbrella_check_recursive(@$_SERVER['PHP_SELF']);

        if (!empty($this->_bigumbrella_doubtfuls)) {
            ob_start(array(
                         $this,
                         'bigumbrella_outputcheck',
                     ));
        }
    }

    /**
     * @param string $s
     *
     * @return string
     */
    public function bigumbrella_outputcheck($s)
    {
        if (defined('BIGUMBRELLA_DISABLED')) {
            return $s;
        }

        if (function_exists('headers_list')) {
            foreach (headers_list() as $header) {
                if (false !== stripos($header, 'Content-Type:') && false === stripos($header, 'text/html')) {
                    return $s;
                }
            }
        }

        if (!is_array($this->_bigumbrella_doubtfuls)) {
            return 'bigumbrella injection found.';
        }

        foreach ($this->_bigumbrella_doubtfuls as $doubtful) {
            if (false !== strpos($s, $doubtful)) {
                return 'XSS found by Protector.';
            }
        }

        return $s;
    }

    /**
     * @return bool
     */
    public function intval_allrequestsendid()
    {
        global $_GET, $_POST, $_COOKIE;

        if ($this->_done_intval) {
            return true;
        } else {
            $this->_done_intval = true;
        }

        foreach ($_GET as $key => $val) {
            if ('id' === substr($key, -2) && !is_array($_GET[$key])) {
                $newval     = preg_replace('/[^0-9a-zA-Z_-]/', '', $val);
                $_GET[$key] = $_GET[$key] = $newval;
                if ($_REQUEST[$key] == $_GET[$key]) {
                    $_REQUEST[$key] = $newval;
                }
            }
        }
        foreach ($_POST as $key => $val) {
            if ('id' === substr($key, -2) && !is_array($_POST[$key])) {
                $newval      = preg_replace('/[^0-9a-zA-Z_-]/', '', $val);
                $_POST[$key] = $_POST[$key] = $newval;
                if ($_REQUEST[$key] == $_POST[$key]) {
                    $_REQUEST[$key] = $newval;
                }
            }
        }
        foreach ($_COOKIE as $key => $val) {
            if ('id' === substr($key, -2) && !is_array($_COOKIE[$key])) {
                $newval        = preg_replace('/[^0-9a-zA-Z_-]/', '', $val);
                $_COOKIE[$key] = $_COOKIE[$key] = $newval;
                if ($_REQUEST[$key] == $_COOKIE[$key]) {
                    $_REQUEST[$key] = $newval;
                }
            }
        }

        return true;
    }

    /**
     * @return bool
     */
    public function eliminate_dotdot()
    {
        global $_GET, $_POST, $_COOKIE;

        if ($this->_done_dotdot) {
            return true;
        } else {
            $this->_done_dotdot = true;
        }

        foreach ($_GET as $key => $val) {
            if (is_array($_GET[$key])) {
                continue;
            }
            if ('../' === substr(trim($val), 0, 3) || false !== strpos($val, '/../')) {
                $this->last_error_type = 'DirTraversal';
                $this->message .= "Directory Traversal '$val' found.\n";
                $this->output_log($this->last_error_type, 0, false, 64);
                $sanitized_val = str_replace(chr(0), '', $val);
                if (' .' !== substr($sanitized_val, -2)) {
                    $sanitized_val .= ' .';
                }
                $_GET[$key] = $_GET[$key] = $sanitized_val;
                if ($_REQUEST[$key] == $_GET[$key]) {
                    $_REQUEST[$key] = $sanitized_val;
                }
            }
        }

        /*    foreach ($_POST as $key => $val) {
                if( is_array( $_POST[ $key ] ) ) continue ;
                if ( substr( trim( $val ) , 0 , 3 ) == '../' || false !== strpos( $val , '../../' ) ) {
                    $this->last_error_type = 'ParentDir' ;
                    $this->message .= "Doubtful file specification '$val' found.\n" ;
                    $this->output_log( $this->last_error_type , 0 , false , 128 ) ;
                    $sanitized_val = str_replace( chr(0) , '' , $val ) ;
                    if( substr( $sanitized_val , -2 ) != ' .' ) $sanitized_val .= ' .' ;
                    $_POST[ $key ] = $_POST[ $key ] = $sanitized_val ;
                    if ($_REQUEST[ $key ] == $_POST[ $key ]) {
                        $_REQUEST[ $key ] = $sanitized_val ;
                    }
                }
            }
            foreach ($_COOKIE as $key => $val) {
                if( is_array( $_COOKIE[ $key ] ) ) continue ;
                if ( substr( trim( $val ) , 0 , 3 ) == '../' || false !== strpos( $val , '../../' ) ) {
                    $this->last_error_type = 'ParentDir' ;
                    $this->message .= "Doubtful file specification '$val' found.\n" ;
                    $this->output_log( $this->last_error_type , 0 , false , 128 ) ;
                    $sanitized_val = str_replace( chr(0) , '' , $val ) ;
                    if( substr( $sanitized_val , -2 ) != ' .' ) $sanitized_val .= ' .' ;
                    $_COOKIE[ $key ] = $_COOKIE[ $key ] = $sanitized_val ;
                    if ($_REQUEST[ $key ] == $_COOKIE[ $key ]) {
                        $_REQUEST[ $key ] = $sanitized_val ;
                    }
                }
            }*/

        return true;
    }

    /**
     * @param array $current
     * @param array $indexes
     *
     * @return bool|string
     */
    public function &get_ref_from_base64index(&$current, $indexes)
    {
        $false = false;
        foreach ($indexes as $index) {
            $index = base64_decode($index);
            if (!is_array($current) || false === $index) {
                return $false;
            }
            $current =& $current[$index];
        }

        return $current;
    }

    /**
     * @param string       $key
     * @param array|string $val
     *
     * @return void
     */
    public function replace_doubtful($key, $val)
    {
        global $_GET, $_POST, $_COOKIE;

        $index_expression = '';
        $indexes          = explode('_', $key);
        $base_array       = array_shift($indexes);

        switch ($base_array) {
            case 'G' :
                $main_ref   =& $this->get_ref_from_base64index($_GET, $indexes);
                $legacy_ref =& $this->get_ref_from_base64index($_GET, $indexes);
                break;
            case 'P' :
                $main_ref   =& $this->get_ref_from_base64index($_POST, $indexes);
                $legacy_ref =& $this->get_ref_from_base64index($_POST, $indexes);
                break;
            case 'C' :
                $main_ref   =& $this->get_ref_from_base64index($_COOKIE, $indexes);
                $legacy_ref =& $this->get_ref_from_base64index($_COOKIE, $indexes);
                break;
            default :
                exit;
        }
        if (!isset($main_ref)) {
            exit;
        }
        $request_ref =& $this->get_ref_from_base64index($_REQUEST, $indexes);
        if (false !== $request_ref && $main_ref == $request_ref) {
            $request_ref = $val;
        }
        $main_ref   = $val;
        $legacy_ref = $val;
    }

    /**
     * @return bool
     */
    public function check_uploaded_files()
    {
        if ($this->_done_badext) {
            return $this->_safe_badext;
        } else {
            $this->_done_badext = true;
        }

        // extensions never uploaded
        $bad_extensions = array(
            'php',
            'phtml',
            'phtm',
            'php3',
            'php4',
            'cgi',
            'pl',
            'asp',
        );
        // extensions needed image check (anti-IE Content-Type XSS)
        $image_extensions = array(
            1  => 'gif',
            2  => 'jpg',
            3  => 'png',
            4  => 'swf',
            5  => 'psd',
            6  => 'bmp',
            7  => 'tif',
            8  => 'tif',
            9  => 'jpc',
            10 => 'jp2',
            11 => 'jpx',
            12 => 'jb2',
            13 => 'swc',
            14 => 'iff',
            15 => 'wbmp',
            16 => 'xbm',
        );

        foreach ($_FILES as $_file) {
            if (!empty($_file['error'])) {
                continue;
            }
            if (!empty($_file['name']) && is_string($_file['name'])) {
                $ext = strtolower(substr(strrchr($_file['name'], '.'), 1));
                if ('jpeg' === $ext) {
                    $ext = 'jpg';
                } elseif ('tiff' === $ext) {
                    $ext = 'tif';
                }

                // anti multiple dot file (Apache mod_mime.c)
                if (substr_count(str_replace('.tar.gz', '.tgz', $_file['name']), '.') + 1 > 2) {
                    $this->message .= "Attempt to multiple dot file {$_file['name']}.\n";
                    $this->_safe_badext    = false;
                    $this->last_error_type = 'UPLOAD';
                }

                // anti dangerous extensions
                if (in_array($ext, $bad_extensions)) {
                    $this->message .= "Attempt to upload {$_file['name']}.\n";
                    $this->_safe_badext    = false;
                    $this->last_error_type = 'UPLOAD';
                }

                // anti camouflaged image file
                if (in_array($ext, $image_extensions)) {
                    $image_attributes = @getimagesize($_file['tmp_name']);
                    if (false === $image_attributes && is_uploaded_file($_file['tmp_name'])) {
                        // open_basedir restriction
                        $temp_file = XOOPS_ROOT_PATH . '/uploads/protector_upload_temporary' . md5(time());
                        move_uploaded_file($_file['tmp_name'], $temp_file);
                        $image_attributes = @getimagesize($temp_file);
                        @unlink($temp_file);
                    }

                    if (false === $image_attributes || $image_extensions[(int)$image_attributes[2]] != $ext) {
                        $this->message .= "Attempt to upload camouflaged image file {$_file['name']}.\n";
                        $this->_safe_badext    = false;
                        $this->last_error_type = 'UPLOAD';
                    }
                }
            }
        }

        return $this->_safe_badext;
    }

    /**
     * @return bool
     */
    public function check_contami_systemglobals()
    {
        /*    if( $this->_done_contami ) return $this->_safe_contami ;
    else $this->_done_contami = true ; */

        /*    foreach ($this->_bad_globals as $bad_global) {
                if ( isset( $_REQUEST[ $bad_global ] ) ) {
                    $this->message .= "Attempt to inject '$bad_global' was found.\n" ;
                    $this->_safe_contami = false ;
                    $this->last_error_type = 'CONTAMI' ;
                }
            }*/

        return $this->_safe_contami;
    }

    /**
     * @param bool $sanitize
     *
     * @return bool
     */
    public function check_sql_isolatedcommentin($sanitize = true)
    {
        if ($this->_done_isocom) {
            return $this->_safe_isocom;
        } else {
            $this->_done_isocom = true;
        }

        foreach ($this->_doubtful_requests as $key => $val) {
            $str = $val;
            while ($str = strstr($str, '/*')) { /* */
                $str = strstr(substr($str, 2), '*/');
                if (false === $str) {
                    $this->message .= "Isolated comment-in found. ($val)\n";
                    if ($sanitize) {
                        $this->replace_doubtful($key, $val . '*/');
                    }
                    $this->_safe_isocom    = false;
                    $this->last_error_type = 'ISOCOM';
                }
            }
        }

        return $this->_safe_isocom;
    }

    /**
     * @param bool $sanitize
     *
     * @return bool
     */
    public function check_sql_union($sanitize = true)
    {
        if ($this->_done_union) {
            return $this->_safe_union;
        } else {
            $this->_done_union = true;
        }

        foreach ($this->_doubtful_requests as $key => $val) {
            $str = str_replace(array('/*', '*/'), '', preg_replace('?/\*.+\*/?sU', '', $val));
            if (preg_match('/\sUNION\s+(ALL|SELECT)/i', $str)) {
                $this->message .= "Pattern like SQL injection found. ($val)\n";
                if ($sanitize) {
                    //                    $this->replace_doubtful($key, preg_replace('/union/i', 'uni-on', $val));
                    $this->replace_doubtful($key, str_ireplace('union', 'uni-on', $val));
                }
                $this->_safe_union     = false;
                $this->last_error_type = 'UNION';
            }
        }

        return $this->_safe_union;
    }

    /**
     * @param int $uid
     *
     * @return bool
     */
    public function stopforumspam($uid)
    {
        if ('POST' !== $_SERVER['REQUEST_METHOD']) {
            return false;
        }

        $result = $this->stopForumSpamLookup(
            isset($_POST['email']) ? $_POST['email'] : null,
            $_SERVER['REMOTE_ADDR'],
            isset($_POST['uname']) ? $_POST['uname'] : null
        );

        if (false === $result || isset($result['http_code'])) {
            return false;
        }

        $spammer = false;
        if (isset($result['email']) && isset($result['email']['lastseen'])) {
            $spammer = true;
        }

        if (isset($result['ip']) && isset($result['ip']['lastseen'])) {
            $last        = strtotime($result['ip']['lastseen']);
            $oneMonth    = 60 * 60 * 24 * 31;
            $oneMonthAgo = time() - $oneMonth;
            if ($last > $oneMonthAgo) {
                $spammer = true;
            }
        }

        if (!$spammer) {
            return false;
        }

        $this->last_error_type = 'SPAMMER POST';

        switch ($this->_conf['stopforumspam_action']) {
            default :
            case 'log' :
                break;
            case 'san' :
                $_POST = array();
                $this->message .= 'POST deleted for IP:' . $_SERVER['REMOTE_ADDR'];
                break;
            case 'biptime0' :
                $_POST = array();
                $this->message .= 'BAN and POST deleted for IP:' . $_SERVER['REMOTE_ADDR'];
                $this->_should_be_banned_time0 = true;
                break;
            case 'bip' :
                $_POST = array();
                $this->message .= 'Ban and POST deleted for IP:' . $_SERVER['REMOTE_ADDR'];
                $this->_should_be_banned = true;
                break;
        }

        $this->output_log($this->last_error_type, $uid, false, 16);

        return true;
    }

    /**
     * @param string $email
     * @param string $ip
     * @param string|null $username
     *
     * @return mixed
     */
    public function stopForumSpamLookup($email, $ip, $username = null)
    {
        if (!function_exists('curl_init')) {
            return false;
        }

        $query = '';
        $query .= (empty($ip)) ? '' : '&ip=' . $ip;
        $query .= (empty($email)) ? '' : '&email=' . $email;
        $query .= (empty($username)) ? '' : '&username=' . $username;

        if (empty($query)) {
            return false;
        }

        $url = 'http://www.stopforumspam.com/api?f=json' . $query;
        $ch  = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        $result = curl_exec($ch);
        if (false === $result) {
            $result = curl_getinfo($ch);
        } else {
            $result = json_decode(curl_exec($ch), true);
        }
        curl_close($ch);

        return $result;
    }

    /**
     * @param int  $uid
     * @param bool $can_ban
     *
     * @return bool
     */
    public function check_dos_attack($uid = 0, $can_ban = false)
    {
        global $xoopsDB;

        if ($this->_done_dos) {
            return true;
        }

        $ip      = \Xmf\IPAddress::fromRequest();
        if (false === $ip->asReadable()) {
            return true;
        }
        $uri     = @$_SERVER['REQUEST_URI'];

        $ip4sql  = $xoopsDB->quote($ip->asReadable());
        $uri4sql = $xoopsDB->quote($uri);

        // gargage collection
        $result = $xoopsDB->queryF(
            'DELETE FROM ' . $xoopsDB->prefix($this->mydirname . '_access')
            . ' WHERE expire < UNIX_TIMESTAMP()'
        );

        // for older versions before updating this module
        if (false === $result) {
            $this->_done_dos = true;

            return true;
        }

        // sql for recording access log (INSERT should be placed after SELECT)
        $sql4insertlog = 'INSERT INTO ' . $xoopsDB->prefix($this->mydirname . '_access')
                         . " SET ip={$ip4sql}, request_uri={$uri4sql},"
                         . " expire=UNIX_TIMESTAMP()+'" . (int)$this->_conf['dos_expire'] . "'";

        // bandwidth limitation
        if (@$this->_conf['bwlimit_count'] >= 10) {
            $result = $xoopsDB->query('SELECT COUNT(*) FROM ' . $xoopsDB->prefix($this->mydirname . '_access'));
            list($bw_count) = $xoopsDB->fetchRow($result);
            if ($bw_count > $this->_conf['bwlimit_count']) {
                $this->write_file_bwlimit(time() + $this->_conf['dos_expire']);
            }
        }

        // F5 attack check (High load & same URI)
        $result = $xoopsDB->query(
            'SELECT COUNT(*) FROM ' . $xoopsDB->prefix($this->mydirname . '_access')
            . " WHERE ip={$ip4sql} AND request_uri={$uri4sql}"
        );
        list($f5_count) = $xoopsDB->fetchRow($result);
        if ($f5_count > $this->_conf['dos_f5count']) {

            // delayed insert
            $xoopsDB->queryF($sql4insertlog);

            // extends the expires of the IP with 5 minutes at least (pending)
            // $result = $xoopsDB->queryF( "UPDATE ".$xoopsDB->prefix($this->mydirname.'_access')." SET expire=UNIX_TIMESTAMP()+300 WHERE ip='$ip4sql' AND expire<UNIX_TIMESTAMP()+300" ) ;

            // call the filter first
            $ret = $this->call_filter('F5attackOverrun');

            // actions for F5 Attack
            $this->_done_dos       = true;
            $this->last_error_type = 'DoS';
            switch ($this->_conf['dos_f5action']) {
                default :
                case 'exit' :
                    $this->output_log($this->last_error_type, $uid, true, 16);
                    exit;
                case 'none' :
                    $this->output_log($this->last_error_type, $uid, true, 16);

                    return true;
                case 'biptime0' :
                    if ($can_ban) {
                        $this->register_bad_ips(time() + $this->_conf['banip_time0']);
                    }
                    break;
                case 'bip' :
                    if ($can_ban) {
                        $this->register_bad_ips();
                    }
                    break;
                case 'hta' :
                    if ($can_ban) {
                        $this->deny_by_htaccess();
                    }
                    break;
                case 'sleep' :
                    sleep(5);
                    break;
            }

            return false;
        }

        // Check its Agent
        if ('' != trim($this->_conf['dos_crsafe']) && preg_match($this->_conf['dos_crsafe'], @$_SERVER['HTTP_USER_AGENT'])) {
            // welcomed crawler
            $this->_done_dos = true;

            return true;
        }

        // Crawler check (High load & different URI)
        $result = $xoopsDB->query(
            'SELECT COUNT(*) FROM ' . $xoopsDB->prefix($this->mydirname . '_access') . " WHERE ip={$ip4sql}"
        );
        list($crawler_count) = $xoopsDB->fetchRow($result);

        // delayed insert
        $xoopsDB->queryF($sql4insertlog);

        if ($crawler_count > $this->_conf['dos_crcount']) {

            // call the filter first
            $ret = $this->call_filter('CrawlerOverrun');

            // actions for bad Crawler
            $this->_done_dos       = true;
            $this->last_error_type = 'CRAWLER';
            switch ($this->_conf['dos_craction']) {
                default :
                case 'exit' :
                    $this->output_log($this->last_error_type, $uid, true, 16);
                    exit;
                case 'none' :
                    $this->output_log($this->last_error_type, $uid, true, 16);

                    return true;
                case 'biptime0' :
                    if ($can_ban) {
                        $this->register_bad_ips(time() + $this->_conf['banip_time0']);
                    }
                    break;
                case 'bip' :
                    if ($can_ban) {
                        $this->register_bad_ips();
                    }
                    break;
                case 'hta' :
                    if ($can_ban) {
                        $this->deny_by_htaccess();
                    }
                    break;
                case 'sleep' :
                    sleep(5);
                    break;
            }

            return false;
        }

        return true;
    }

    //
    /**
     * @return bool|null
     */
    public function check_brute_force()
    {
        global $xoopsDB;

        $ip      = \Xmf\IPAddress::fromRequest();
        if (false === $ip->asReadable()) {
            return true;
        }
        $uri     = @$_SERVER['REQUEST_URI'];
        $ip4sql  = $xoopsDB->quote($ip->asReadable());
        $uri4sql = $xoopsDB->quote($uri);

        $victim_uname = empty($_COOKIE['autologin_uname']) ? $_POST['uname'] : $_COOKIE['autologin_uname'];
        // some UA send 'deleted' as a value of the deleted cookie.
        if ('deleted' === $victim_uname) {
            return null;
        }
        $mal4sql = $xoopsDB->quote("BRUTE FORCE: $victim_uname");

        // garbage collection
        $result = $xoopsDB->queryF(
            'DELETE FROM ' . $xoopsDB->prefix($this->mydirname . '_access') . ' WHERE expire < UNIX_TIMESTAMP()'
        );

        // sql for recording access log (INSERT should be placed after SELECT)
        $sql4insertlog = 'INSERT INTO ' . $xoopsDB->prefix($this->mydirname . '_access')
                         . " SET ip={$ip4sql}, request_uri={$uri4sql}, malicious_actions={$mal4sql}, expire=UNIX_TIMESTAMP()+600";

        // count check
        $result = $xoopsDB->query(
            'SELECT COUNT(*) FROM ' . $xoopsDB->prefix($this->mydirname . '_access')
            . " WHERE ip={$ip4sql} AND malicious_actions like 'BRUTE FORCE:%'"
        );
        list($bf_count) = $xoopsDB->fetchRow($result);
        if ($bf_count > $this->_conf['bf_count']) {
            $this->register_bad_ips(time() + $this->_conf['banip_time0']);
            $this->last_error_type = 'BruteForce';
            $this->message .= "Trying to login as '" . addslashes($victim_uname) . "' found.\n";
            $this->output_log('BRUTE FORCE', 0, true, 1);
            $ret = $this->call_filter('BruteforceOverrun');
            if (false === $ret) {
                exit;
            }
        }
        // delayed insert
        $xoopsDB->queryF($sql4insertlog);
        return null;
    }

    /**
     * @param array|string $val
     *
     * @return void
     */
    protected function _spam_check_point_recursive($val)
    {
        if (is_array($val)) {
            foreach ($val as $subval) {
                $this->_spam_check_point_recursive($subval);
            }
        } else {
            // http_host
            $path_array = parse_url(XOOPS_URL);
            $http_host  = empty($path_array['host']) ? 'www.xoops.org' : $path_array['host'];

            // count URI up
            $count = -1;
            foreach (preg_split('#https?\:\/\/#i', $val) as $fragment) {
                if (0 !== strncmp($fragment, $http_host, strlen($http_host))) {
                    ++$count;
                }
            }
            if ($count > 0) {
                $this->_spamcount_uri += $count;
            }

            // count BBCode likd [url=www....] up (without [url=http://...])
            $this->_spamcount_uri += count(preg_split('/\[url=(?!http|\\"http|\\\'http|' . $http_host . ')/i', $val)) - 1;
        }
    }

    /**
     * @param int $points4deny
     * @param int $uid
     *
     * @return void
     */
    public function spam_check($points4deny, $uid)
    {
        $this->_spamcount_uri = 0;
        $this->_spam_check_point_recursive($_POST);

        if ($this->_spamcount_uri >= $points4deny) {
            $this->message .= @$_SERVER['REQUEST_URI'] . " SPAM POINT: $this->_spamcount_uri\n";
            $this->output_log('URI SPAM', $uid, false, 128);
            $ret = $this->call_filter('SpamcheckOverrun');
            if (false === $ret) {
                exit;
            }
        }
    }

    /**
     * @return void
     */
    public function disable_features()
    {
        global $_POST, $_GET, $_COOKIE;

        // disable "Notice: Undefined index: ..."
        $error_reporting_level = error_reporting(0);

        //
        // bit 1 : disable XMLRPC , criteria bug
        //
        if ($this->_conf['disable_features'] & 1) {

            // zx 2005/1/5 disable xmlrpc.php in root
            if (/* ! stristr( $_SERVER['SCRIPT_NAME'] , 'modules' ) && */
                'xmlrpc.php' === substr(@$_SERVER['SCRIPT_NAME'], -10)
            ) {
                $this->output_log('xmlrpc', 0, true, 1);
                exit;
            }

            // security bug of class/criteria.php 2005/6/27
            if ((isset($_POST['uname']) && '0' === $_POST['uname']) || (isset($_COOKIE['autologin_pass']) && '0' === $_COOKIE['autologin_pass'])) {
                $this->output_log('CRITERIA');
                exit;
            }
        }

        //
        // bit 11 : XSS+CSRFs in XOOPS < 2.0.10
        //
        if ($this->_conf['disable_features'] & 1024) {

            // root controllers
            if (false === stripos(@$_SERVER['SCRIPT_NAME'], 'modules')) {
                // zx 2004/12/13 misc.php debug (file check)
                if ('misc.php' === substr(@$_SERVER['SCRIPT_NAME'], -8) && ('debug' === $_GET['type'] || 'debug' === $_POST['type']) && !preg_match('/^dummy_\d+\.html$/', $_GET['file'])) {
                    $this->output_log('misc debug');
                    exit;
                }

                // zx 2004/12/13 misc.php smilies
                if ('misc.php' === substr(@$_SERVER['SCRIPT_NAME'], -8) && ('smilies' === $_GET['type'] || 'smilies' === $_POST['type']) && !preg_match('/^[0-9a-z_]*$/i', $_GET['target'])) {
                    $this->output_log('misc smilies');
                    exit;
                }

                // zx 2005/1/5 edituser.php avatarchoose
                if ('edituser.php' === substr(@$_SERVER['SCRIPT_NAME'], -12) && 'avatarchoose' === $_POST['op'] && false !== strpos($_POST['user_avatar'], '..')) {
                    $this->output_log('edituser avatarchoose');
                    exit;
                }
            }

            // zx 2005/1/4 findusers
            if ('modules/system/admin.php' === substr(@$_SERVER['SCRIPT_NAME'], -24) && ('findusers' === $_GET['fct'] || 'findusers' === $_POST['fct'])) {
                foreach ($_POST as $key => $val) {
                    if (false !== strpos($key, "'") || false !== strpos($val, "'")) {
                        $this->output_log('findusers');
                        exit;
                    }
                }
            }

            // preview CSRF zx 2004/12/14
            // news submit.php
            if ('modules/news/submit.php' === substr(@$_SERVER['SCRIPT_NAME'], -23) && isset($_POST['preview']) && 0 !== strpos(@\Xmf\Request::getString('HTTP_REFERER', '', 'SERVER'), XOOPS_URL . '/modules/news/submit.php')) {
                $_POST['nohtml'] = $_POST['nohtml'] = 1;
            }
            // news admin/index.php
            if ('modules/news/admin/index.php' === substr(@$_SERVER['SCRIPT_NAME'], -28) && ('preview' === $_POST['op'] || 'preview' === $_GET['op']) && 0 !== strpos(@\Xmf\Request::getString('HTTP_REFERER', '', 'SERVER'), XOOPS_URL . '/modules/news/admin/index.php')) {
                $_POST['nohtml'] = $_POST['nohtml'] = 1;
            }
            // comment comment_post.php
            if (isset($_POST['com_dopreview']) && false === strpos(substr(@\Xmf\Request::getString('HTTP_REFERER', '', 'SERVER'), -16), 'comment_post.php')) {
                $_POST['dohtml'] = $_POST['dohtml'] = 0;
            }
            // disable preview of system's blocksadmin
            if ('modules/system/admin.php' === substr(@$_SERVER['SCRIPT_NAME'], -24) && ('blocksadmin' === $_GET['fct'] || 'blocksadmin' === $_POST['fct']) && isset($_POST['previewblock']) /* && strpos( \Xmf\Request::getString('HTTP_REFERER', '', 'SERVER') , XOOPS_URL.'/modules/system/admin.php' ) !== 0 */) {
                die("Danger! don't use this preview. Use 'altsys module' instead.(by Protector)");
            }
            // tpl preview
            if ('modules/system/admin.php' === substr(@$_SERVER['SCRIPT_NAME'], -24) && ('tplsets' === $_GET['fct'] || 'tplsets' === $_POST['fct'])) {
                if ('previewpopup' === $_POST['op'] || 'previewpopup' === $_GET['op'] || isset($_POST['previewtpl'])) {
                    die("Danger! don't use this preview.(by Protector)");
                }
            }
        }

        // restore reporting level
        error_reporting($error_reporting_level);
    }

    /**
     * @param string $type
     * @param string $dying_message
     *
     * @return int|mixed
     */
    public function call_filter($type, $dying_message = '')
    {
//        require_once __DIR__ . '/ProtectorFilter.php';
        $filterHandler = FilterHandler::getInstance();
        $ret            = $filterHandler->execute($type);
        if (false === $ret && $dying_message) {
            die($dying_message);
        }

        return $ret;
    }
}
