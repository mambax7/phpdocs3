<?php

/**
 * Cookie class
 *
 * You may not change or alter any portion of this comment or credits
 * of supporting developers from this source code or any supporting source code
 * which is considered copyrighted (c) material of the original comment or credit authors.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * @copyright       (c) 2000-2016 XOOPS Project (www.xoops.org)
 * @license             GNU GPL 2 or later (https://www.gnu.org/licenses/gpl-2.0.html)
 * @author              Andricq Nicolas (AKA MusS)
 * @package             system
 *
 * @deprecated          since 2.5.9 - will be removed in future versions
 */
class Cookie
{
    // Reserved session keys
    /**
     * @var array
     */
    private static $_reserved = array(
        'XOLOGGERVIEW',
        'xoops_user',
    );
    // Static class cannot be initialized

    /**
     *
     */
    private function __construct()
    {
    }

    // Alias for delete() function

    /**
     * @param $key
     */
    public static function del($key)
    {
        self::delete($key);
    }

    // Delete a cookie

    /**
     * @param $key
     */
    public static function delete($key)
    {
        // Change string representation array to key/value array
        $key = self::_scrubKey($key);

        // Make sure the cookie exists
        if (self::exists($key)) {
            // Check for key array
            if (is_array($key)) {
                // Grab key/value pair
                list($k, $v) = each($key);

                // Set string representation
                $key = $k . '[' . $v . ']';

                // Set expiration time to -1hr (will cause browser deletion)
                setcookie($key, false, time() - 3600);

                // Unset the cookie
                unset($_COOKIE[$k][$v]);
            } // Check for cookie array
            elseif (is_array($_COOKIE[$key])) {
                foreach ($_COOKIE[$key] as $k => $v) {
                    // Set string representation
                    $cookie = $key . '[' . $k . ']';

                    // Set expiration time to -1hr (will cause browser deletion)
                    setcookie($cookie, false, time() - 3600);

                    // Unset the cookie
                    unset($_COOKIE[$key][$k]);
                }
            } // Unset single cookie
            else {
                // Set expiration time to -1hr (will cause browser deletion)
                setcookie($key, false, time() - 3600);

                // Unset key
                unset($_COOKIE[$key]);
            }
        }
    }

    // See if a cookie key exists

    /**
     * @param $key
     *
     * @return bool
     */
    public static function exists($key)
    {
        // Change string representation array to key/value array
        $key = self::_scrubKey($key);

        // Check for array
        if (is_array($key)) {
            // Grab key/value pair
            list($k, $v) = each($key);

            // Check for key/value pair and return
            if (isset($_COOKIE[$k][$v])) {
                return true;
            }
        } // If key exists, return true
        elseif (isset($_COOKIE[$key])) {
            return true;
        }

        // Key does not exist
        return false;
    }

    // Get cookie information

    /**
     * @param $key
     *
     * @return null
     */
    public static function get($key)
    {
        // Change string representation array to key/value array
        $key = self::_scrubKey($key);

        // Check for array
        if (is_array($key)) {
            // Grab key/value pair
            list($k, $v) = each($key);

            // Check for key/value pair and return
            if (isset($_COOKIE[$k][$v])) {
                return $_COOKIE[$k][$v];
            }
        } // Return single key if it's set
        elseif (isset($_COOKIE[$key])) {
            return $_COOKIE[$key];
        } // Otherwise return null
        else {
            return null;
        }

        return null;
    }

    // Return the cookie array

    /**
     * @return array
     */
    public static function contents()
    {
        return $_COOKIE;
    }

    // Set cookie information

    /**
     * @param mixed  $key
     * @param string $value
     * @param int    $expire
     * @param string $path
     * @param string $domain
     * @param bool   $secure
     * @param bool   $httponly
     */
    public static function set(
        $key, 
        $value, 
        $expire = 0,            /* Default expire time (session, 1 week = 604800) */
        $path = '',             /* Default path */
        $domain = '',           /* Default domain */
        $secure = false,        /* Does this cookie need a secure HTTPS connection? */
        $httponly = true        /* Can non-HTTP services access this cookie (IE: javascript)? */
    )
    {
        // Make sure they aren't trying to set a reserved word
        if (!in_array($key, self::$_reserved)) {
            // If $key is in array format, change it to string representation
            $key = self::_scrubKey($key, true);

            // Store the cookie
            setcookie($key, $value, $expire, $path, $domain, $secure, $httponly);
        } // Otherwise, throw an error
        else {
            Error::warning('Could not set key -- it is reserved.', __CLASS__);
        }
    }

    // Converts strings to arrays (or vice versa if toString = true)
    /**
     * @param      $key
     * @param bool $toString
     *
     * @return array|string
     */
    private static function _scrubKey($key, $toString = false)
    {
        // Converting from array to string
        if ($toString) {
            // If $key is in array format, change it to string representation
            if (is_array($key)) {
                // Grab key/value pair
                list($k, $v) = each($key);

                // Set string representation
                $key = $k . '[' . $v . ']';
            }
        } // Converting from string to array
        elseif (!is_array($key)) {
            // is this a string representation of an array?
            if (preg_match('/([\w\d]+)\[([\w\d]+)\]$/i', $key, $matches)) {
                // Store as key/value pair
                $key = array($matches[1] => $matches[2]);
            }
        }

        // Return key
        return $key;
    }
}
