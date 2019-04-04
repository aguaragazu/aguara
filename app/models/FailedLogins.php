<?php

use Illuminate\Database\Eloquent\Model;

class FailedLogins extends Model
{
    /**
     * Table name for this & extending classes.
     *
     * @var string
     */
    protected $table = 'failed_logins';

    /**
     * Fields names for this & extending classes.
     *
     * @var string
     */
    protected $fillable = ['user_email', 'last_failed_login', 'failed_login_attempts'];

    public $timestamps = false;
}
