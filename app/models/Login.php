<?php

use Illuminate\Database\Eloquent\Model;

/**
 * Login Class.
 *
 * @license    http://opensource.org/licenses/MIT The MIT License (MIT)
 * @author     Omar El Gabry <omar.elgabry.93@gmail.com>
 */
class Login extends Model
{
    /**
     * register a new user.
     *
     * @param string $name
     * @param string $email
     * @param string $password
     * @param string $confirmPassword
     * @param array  $captcha         holds the user's text and original captcha in session
     *
     * @return bool
     */
    public function register($name, $email, $password, $confirmPassword, $captcha)
    {
        $isValid = true;
        $validation = new Validation();

        if (!$validation->validate([
            'User Name' => [$name, 'required|alphaNumWithSpaces|minLen(4)|maxLen(30)'],
            'Email' => [$email, 'required|email|emailUnique|maxLen(50)'],
            'Password' => [$password, 'required|equals('.$confirmPassword.')|minLen(6)|password'],
            'Password Confirmation' => [$confirmPassword, 'required'], ])) {
            $this->errors = $validation->errors();
            $isValid = false;
        }

        // validate captcha
        if (empty($captcha['user']) || strtolower($captcha['user']) !== strtolower($captcha['session'])) {
            $this->errors[] = "The entered characters for captcha don't match";
            $isValid = false;
        }

        if (!$isValid) {
            return false;
        }
        $user = new User();

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT, array('cost' => Config::get('HASH_COST_FACTOR')));
        // email token and time of generating it
        $token = sha1(uniqid(mt_rand(), true));

        // it's very important to use transaction to ensure both:
        // 1. user will be inserted to database
        // 2. the verification email will be sent
        $user->create([
            'name' => $name,
            'email' => $email,
            'role' => 'user',
            'hashed_password' => $hashedPassword,
            'email_token' => $token,
            'email_last_verification' => time(),
        ]);

        $id = $user->id;
        Email::sendEmail(Config::get('EMAIL_EMAIL_VERIFICATION'), $email, ['name' => $name, 'id' => $id], ['email_token' => $token]);

        $database->commit();

        return true;
    }

    /**
     * login.
     *
     * @param string $email
     * @param string $password
     * @param bool   $rememberMe
     * @param string $userIp
     * @param string $userAgent
     *
     * @return bool
     */
    public function doLogIn($email, $password, $rememberMe, $userIp, $userAgent)
    {
        // 1. check if user is blocked
        if ($this->isIpBlocked($userIp)) {
            $this->errors[] = 'Your IP Address has been blocked';

            return false;
        }
        //dd($this->isIpBlocked($userIp));
        // 2. validate only presence
        $validation = new Validation();
        if (!$validation->validate([
            'Your Email' => [$email, 'required'],
            'Your Password' => [$password, 'required'], ])) {
            $this->errors = $validation->errors();

            return false;
        }
        //dd($this->errors);
        // 3. check if user has previous failed login attempts
        $failedLogin = FailedLogins::where('user_email', $email)->get()->toArray();

        $last_time = isset($failedLogin['last_failed_login']) ? $failedLogin['last_failed_login'] : null;
        $count = isset($failedLogin['failed_login_attempts']) ? $failedLogin['failed_login_attempts'] : null;

        // check if the failed login attempts exceeded limits
        // @see Validation::attempts()
        if (!$validation->validate([
            'Failed Login' => [['last_time' => $last_time, 'count' => $count], 'attempts'], ])) {
            $this->errors = $validation->errors();

            return false;
        }

        // 4. get user from database
        $user = User::where('email', '=', $email)
            ->where('is_email_activated', '=', 1)
            ->first();
        // dd($user);
        $userId = isset($user['id']) ? $user['id'] : null;
        $hashedPassword = isset($user['hashed_password']) ? $user['hashed_password'] : null;

        // 5. validate data returned from users table
        if (!$validation->validate([
            'Login' => [['user_id' => $userId, 'hashed_password' => $hashedPassword, 'password' => $password], 'credentials'], ])) {
            // if not valid, then increment number of failed logins
            $this->incrementFailedLogins($email, $failedLogin);

            // also, check if current IP address is trying to login using multiple accounts,
            // if so, then block it, if not, just add a new record to database
            $this->handleIpFailedLogin($userIp, $email);

            $this->errors = $validation->errors();
            // dd($this->errors);

            return false;
        }

        // reset session
        Session::reset(['user_id' => $userId, 'role' => $user['role'], 'ip' => $userIp, 'user_agent' => $userAgent]);

        // if remember me checkbox is checked, then save data to cookies as well
        if (!empty($rememberMe) && 'rememberme' === $rememberMe) {
            // reset cookie, Cookie token usable only once
            Cookie::reset($userId);
        } else {
            Cookie::remove($userId);
        }

        // if user credentials are valid then,
        // reset failed logins & forgotten password tokens
        $this->resetFailedLogins($email);
        $this->resetPasswordToken($userId);

        return true;
    }

    /**
     * block IP Address.
     *
     * @param string $userIp
     */
    private function blockIp($userIp)
    {
        // if user is already blocked, this method won't be triggered
        /*if(!$this->isIpBlocked($userIp)){}*/
        BlockedIps::create(['ip' => $userIp]);
    }

    /**
     * is IP Address blocked?
     *
     * @param string $userIp
     *
     * @return bool
     */
    private function isIpBlocked($userIp)
    {
        $blocked = BlockedIps::where('ip', '=', $userIp)->count();

        return $blocked >= 1;
    }

    /**
     * Adds a new record(if not exists) to ip_failed_logins table,
     * Also block the IP Address if number of attempts exceeded.
     *
     * @param string $userIp
     * @param string $email
     */
    private function handleIpFailedLogin($userIp, $email)
    {
        $count = IpFailedLogins::where('ip', '=', $userIp)->count();
        // block IP if there were failed login attempts using different emails(>= 10) from the same IP address
        if ($count >= 10) {
            $this->blockIp($userIp);
        } else {
            // check if ip_failed_logins already has a record with current ip + email
            // if not, then insert it.
            $ips = BlockedIps::get(['ip'])->toArray();
            if (!in_array(['ip' => $userIp, 'user_email' => $email], $ips, true)) {
                IpFailedLogins::create([
                    'ip' => $userIp,
                    'user_email' => $email,
                ]);
            }
        }
    }

    /**
     * Increment number of failed logins.
     *
     * @param string $email
     * @param array  $failedLogin It determines if there was a previous record in the database or not
     *
     * @throws Exception If couldn't increment failed logins
     */
    private function incrementFailedLogins($email, $failedLogin)
    {
        // Remember? the user_email we are using here is not a foreign key from users table
        // Why? because this will block even un registered users
        // dd($email, $failedLogin, !empty($failedLogin), empty($failedLogin));
        if (!empty($failedLogin)) {
            FailedLogins::firstOrFail()->where('user_email', $email)->increment('failed_login_attempts', 1);
            FailedLogins::firstOrFail()->where('user_email', $email)->update(['last_failed_login' => time()]);
        } else {
            $FailedLogins = new FailedLogins();
            $FailedLogins->user_email = $email;
            $FailedLogins->last_failed_login = time();
            $FailedLogins->failed_login_attempts = 1;
            $FailedLogins->save();
        }
        /*
                if (!$result) {
                    throw new Exception('FAILED LOGIN', "Couldn't increment failed logins of User Email: ".$email, __FILE__, __LINE__);
                }
        */
    }

    /**
     * Reset failed logins.
     *
     * @param string $email
     *
     * @throws Exception If couldn't reset failed logins
     */
    private function resetFailedLogins($email)
    {
        $FailedLogins = FailedLogins::firstOrFail()->where('user_email', $email);
        $FailedLogins->last_failed_login = '';
        $FailedLogins->failed_login_attempts = 0;
        $FailedLogins->save();

        /*

        if (!$result) {
            throw new Exception("Couldn't reset failed logins for User Email ".$email);
        }*/
    }

    /**
     * What if user forgot his password?
     *
     * @param string $email
     *
     * @return bool
     */
    public function forgotPassword($email)
    {
        $validation = new Validation();
        if (!$validation->validate(['Email' => [$email, 'required|email']])) {
            $this->errors = $validation->errors();

            return false;
        }

        if ($this->isEmailExists($email)) {
            // depends on the last query made by isEmailExists()
            $database = Database::openConnection();
            $user = $database->fetchAssociative();

            // If no previous records in forgotten_passwords, So, $forgottenPassword will be FALSE.
            $database->getByUserId('forgotten_passwords', $user['id']);
            $forgottenPassword = $database->fetchAssociative();

            $last_time = isset($forgottenPassword['password_last_reset']) ? $forgottenPassword['password_last_reset'] : null;
            $count = isset($forgottenPassword['forgotten_password_attempts']) ? $forgottenPassword['forgotten_password_attempts'] : null;

            if (!$validation->validate(['Failed Login' => [['last_time' => $last_time, 'count' => $count], 'attempts']])) {
                $this->errors = $validation->errors();

                return false;
            }

            // You need to get the new password token from the database after updating/inserting it
            $newPasswordToken = $this->generateForgottenPasswordToken($user['id'], $forgottenPassword);

            Email::sendEmail(Config::get('EMAIL_PASSWORD_RESET'), $user['email'], ['id' => $user['id'], 'name' => $user['name']], $newPasswordToken);
        }

        // This will return true even if the email doesn't exists,
        // because you don't want to give any clue
        // to (un)authenticated user if email is actually exists or not
        return true;
    }

    /**
     * Checks if email exists and activated in the database or not.
     *
     * @param string $email
     *
     * @return bool
     */
    private function isEmailExists($email)
    {
        // email is already unique in the database,
        // So, we can't have more than 2 users with the same emails
        $existe = User::where('email', $email)
            ->where('is_email_activated', 1)
            ->count();

        return 1 === $existe;
    }

    /**
     * Insert or Update(if already exists).
     *
     * @param int   $userId
     * @param array $forgottenPassword It determines if there was a previous record in the database or not
     *
     * @return array new generated forgotten Password token
     *
     * @throws Exception if couldn't generate the token
     */
    private function generateForgottenPasswordToken($userId, $forgottenPassword)
    {
        // generate random hash for email verification (40 char string)
        $passwordToken = sha1(uniqid(mt_rand(), true));

        if (!empty($forgottenPassword)) {
            $forgotten = ForgottenPassword::firstOrFail()->where('user_id', $userId);
            $forgotten->password_token = $passwordToken;
            $forgotten->password_last_reset = time();
            $forgotten->increment('forgotten_password_attempts');
            $forgotten->save();
        } else {
            $forgotten = new ForgottenPassword();
            $forgotten->user_id = $userId;
            $forgotten->password_token = $passwordToken;
            $forgotten->password_last_reset = time();
            $forgotten->forgotten_password_attempts = 1;
            $forgotten->save();
        }

        /*

        if (!$result) {
            throw new Exception("Couldn't generate token");
        }
        */
        return ['password_token' => $passwordToken];
    }

    /**
     * Checks if forgotten password token is valid or not.
     *
     * @param int    $userId
     * @param string $passwordToken
     *
     * @return bool
     */
    public function isForgottenPasswordTokenValid($userId, $passwordToken)
    {
        if (empty($userId) || empty($passwordToken)) {
            return false;
        }

        $forgottenPassword = ForgottenPassword::firstOrFail()->where('user_id', $userId)
            ->where('password_token', $passwordToken)
            ->toArray();

        // esto es por la migracion de database a eloquent
        if (count($forgottenPassword)) {
            $database[$userId] = $forgottenPassword;
        }

        // $database = Database::openConnection();
        // $database->prepare('SELECT * FROM forgotten_passwords WHERE user_id = :user_id AND password_token = :password_token LIMIT 1');
        // $database->bindValue(':user_id', $userId);
        // $database->bindValue(':password_token', $passwordToken);
        // $database->execute();
        // $forgottenPassword = $database->fetchAssociative();

        // It's bad to send the users any passwords, because you can't be sure if the email will be secured,
        // Also don't send plain text password,
        // So, sending a token that will be expired after 24 hours is better.
        $expiry_time = (24 * 60 * 60);
        $time_elapsed = time() - $forgottenPassword['password_last_reset'];

        if (1 === count($database) && $time_elapsed < $expiry_time) {
            // reset token only after the user enters his password.
            return true;
        } elseif (1 === count($database) && $time_elapsed > $expiry_time) {
            // reset if the user id & token exists in the database, but exceeded the $expiry_time
            $this->resetPasswordToken($userId);

            return false;
        } else {
            // reset the token if invalid,
            // But, if the user id was invalid, this won't make any affect on database
            $this->resetPasswordToken($userId);
            Logger::log('PASSWORD TOKEN', 'User ID '.$userId.' is trying to reset password using invalid token: '.$passwordToken, __FILE__, __LINE__);

            return false;
        }
    }

    /**
     * update password after validating the password token.
     *
     * @param int    $userId
     * @param string $password
     * @param string $confirmPassword
     *
     * @return bool
     *
     * @throws Exception If password couldn't be updated
     */
    public function updatePassword($userId, $password, $confirmPassword)
    {
        $validation = new Validation();
        if (!$validation->validate([
            'Password' => [$password, 'required|equals('.$confirmPassword.')|minLen(6)|password'],
            'Password Confirmation' => [$confirmPassword, 'required'], ])) {
            $this->errors = $validation->errors();

            return false;
        }

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT, array('cost' => Config::get('HASH_COST_FACTOR')));
        $user = User::firstOrFail()->where('id', $userId);
        $user->hashed_password = $hashedPassword;
        $user->save();

        // $database = Database::openConnection();

        // $query = 'UPDATE users SET hashed_password = :hashed_password WHERE id = :id LIMIT 1';
        // $database->prepare($query);
        // $database->bindValue(':hashed_password', $hashedPassword);
        // $database->bindValue(':id', $userId);
        // $result = $database->execute();

        // if (!$result) {
        //     throw new Exception("Couldn't update password");
        // }

        // resetting the password token comes ONLY after successful updating password
        $this->resetPasswordToken($userId);

        return true;
    }

    /**
     * Reset forgotten password token.
     *
     * @param int $userId
     *
     * @throws Exception If couldn't reset password token
     */
    private function resetPasswordToken($userId)
    {
        $forgottenPassword = ForgottenPassword::firstOrFail()->where('user_id', $userId);
        $forgottenPassword->password_token = null;
        $forgottenPassword->password_last_reset = null;
        $forgottenPassword->forgotten_password_attempts = 0;
        $forgottenPassword->save();

        // $database = Database::openConnection();
        // $query = 'UPDATE forgotten_passwords SET password_token = NULL, '.
        //          'password_last_reset = NULL, forgotten_password_attempts = 0 '.
        //          'WHERE user_id = :user_id LIMIT 1';

        // $database->prepare($query);
        // $database->bindValue(':user_id', $userId);
        // $result = $database->execute();
        // if (!$result) {
        //     throw new Exception("Couldn't reset password token");
        // }
    }

    /**
     * It checks if the token for email verification is valid or not.
     *
     * @param int    $userId
     * @param string $emailToken Email Token
     *
     * @return bool if valid, it will return true, and vice-versa
     */
    public function isEmailVerificationTokenValid($userId, $emailToken)
    {
        if (empty($userId) || empty($emailToken)) {
            return false;
        }

        $user = User::firstOrFail()->where('id', $userId)->toArray();

        // $database = Database::openConnection();
        // $database->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        // $database->bindValue(':id', $userId);
        // $database->execute();
        // $user = $database->fetchAssociative();

        $isTokenValid = ($user['email_token'] === $emailToken) ? true : false;

        // check if user is already verified
        if (!empty($user['is_email_activated'])) {
            $this->resetEmailVerificationToken($userId, true);

            return false;
        }

        // setting expiry time on email verification is much better,
        // you can't be sure if the email will be secured,
        // also any user can register with email of another person,
        // so this person won't be able to register at all!.
        $expiry_time = (24 * 60 * 60);
        $time_elapsed = time() - $user['email_last_verification'];

        // token is usable only once.
        if ($user && $isTokenValid && $time_elapsed < $expiry_time) {
            $this->resetEmailVerificationToken($userId, true);

            return true;
        } elseif ($user && $isTokenValid && $time_elapsed > $expiry_time) {
            $this->resetEmailVerificationToken($userId, false);

            return false;
        } else {
            // reset token if invalid,
            // But, if the user id was invalid, this won't make any affect on database
            $this->resetEmailVerificationToken($userId, false);
            Logger::log('EMAIL TOKEN', 'User ID '.$userId.' is trying to access using invalid email token '.$emailToken, __FILE__, __LINE__);

            return false;
        }
    }

    /**
     * Reset the email verification token.
     * Resetting the token depends on whether the email token was valid or not.
     *
     * @param int  $userId
     * @param bool $isValid
     *
     * @throws Exception If couldn't reset email verification token
     */
    public function resetEmailVerificationToken($userId, $isValid)
    {
        $user = User::firstOrFail()->where('id', $userId);

        // $database = Database::openConnection();

        if ($isValid) {
            $user->email_token = null;
            $user->email_last_verification = null;
            $user->is_email_activated = 1;
            $user->save();

        // $query = 'UPDATE users SET email_token = NULL, '.
            //     'email_last_verification = NULL, is_email_activated = 1 '.
            //     'WHERE id = :id LIMIT 1';
        } else {
            $res = User::where('id', $userId)->delete();

            // $query = 'DELETE FROM users WHERE id = :id';
        }

        // $database->prepare($query);
        // $database->bindValue(':id', $userId);
        // $result = $database->execute();
        // if (!$result) {
        //     throw new Exception("Couldn't reset email verification token");
        // }
    }

    /**
     * Logout by removing the Session and Cookies.
     *
     * @param int $userId
     */
    public function logOut($userId)
    {
        Session::remove();
        Cookie::remove($userId);
    }
}
