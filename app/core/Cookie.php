<?php

use User;

/**
 * Cookie Class.
 *
 * @license    http://opensource.org/licenses/MIT The MIT License (MIT)
 * @author     Omar El Gabry <omar.elgabry.93@gmail.com>
 */
class Cookie
{
    /**
     * @var string User ID
     */
    private static $userId = null;

    /**
     * @var string Cookie Token
     */
    private static $token = null;

    /**
     * @var string Hashed Token = hash(User ID . ":" . Token . Cookie Secret)
     */
    private static $hashedCookie = null;

    /**
     * This is the constructor for Cookie object.
     */
    private function __construct()
    {
    }

    /**
     * Getters for $userId.
     *
     * @static static method
     *
     * @return string User ID
     */
    public static function getUserId()
    {
        return (int) self::$userId;
    }

    /**
     * Extract and validate cookie.
     *
     * @static static method
     *
     * @return bool
     */
    public static function isCookieValid()
    {
        // "auth" or "remember me" cookie
        if (empty($_COOKIE['auth'])) {
            return false;
        }

        // check the count before using explode
        $cookie_auth = explode(':', $_COOKIE['auth']);
        if (3 !== count($cookie_auth)) {
            self::remove();

            return false;
        }

        list($encryptedUserId, self::$token, self::$hashedCookie) = $cookie_auth;

        // Remember? $hashedCookie was generated from the original user Id, NOT from the encrypted one.
        self::$userId = Encryption::decrypt($encryptedUserId);

        if (self::$hashedCookie === hash('sha256', self::$userId.':'.self::$token.Config::get('COOKIE_SECRET_KEY')) && !empty(self::$token) && !empty(self::$userId)) {
            $users = User::where('id', '=', self::$userId)
                ->where('cookie_token', '=', self::$token)
                ->count();

            $isValid = 1 === $users ? true : false;
        } else {
            $isValid = false;
        }

        if (!$isValid) {
            Logger::log('COOKIE', self::$userId.' is trying to login using invalid cookie: '.self::$token, __FILE__, __LINE__);
            self::remove(self::$userId);
        }

        return  $isValid;
    }

    /**
     * Remove cookie from the database of a user(if exists),
     * and also from the browser.
     *
     * @static static  method
     *
     * @param string $userId
     */
    public static function remove($userId = null)
    {
        if (!empty($userId)) {
            $user = User::find($userId);
            $user->cookie_token = null;
            $result = $user->save();

            if (!$result) {
                Logger::log('COOKIE', "Couldn't remove cookie from the database for user ID: ".$userId, __FILE__, __LINE__);
            }
        }

        self::$userId = self::$token = self::$hashedCookie = null;

        // How to kill/delete a cookie in a browser?
        setcookie('auth', false, time() - (3600 * 3650), Config::get('COOKIE_PATH'), Config::get('COOKIE_DOMAIN'), Config::get('COOKIE_SECURE'), Config::get('COOKIE_HTTP'));
    }

    /**
     * Reset Cookie,
     * resetting is done by updating the database,
     * and resetting the "auth" cookie in the browser.
     *
     * @static  static method
     *
     * @param string $userId
     */
    public static function reset($userId)
    {
        self::$userId = $userId;
        self::$token = hash('sha256', mt_rand());

        $user = User::find(self::$userId);
        $user->cookie_token = self::$token;
        $user->save();

        if (!$result) {
            Logger::log('COOKIE', "Couldn't remove cookie from the database for user ID: ".$userId, __FILE__, __LINE__);
        }

        // generate cookie string(remember me)
        // Don't expose the original user id in the cookie, Encrypt It!
        $cookieFirstPart = Encryption::encrypt(self::$userId).':'.self::$token;

        // $hashedCookie generated from the original user Id, NOT from the encrypted one.
        self::$hashedCookie = hash('sha256', self::$userId.':'.self::$token.Config::get('COOKIE_SECRET_KEY'));
        $authCookie = $cookieFirstPart.':'.self::$hashedCookie;

        setcookie('auth', $authCookie, time() + Config::get('COOKIE_EXPIRY'), Config::get('COOKIE_PATH'), Config::get('COOKIE_DOMAIN'), Config::get('COOKIE_SECURE'), Config::get('COOKIE_HTTP'));
    }
}
