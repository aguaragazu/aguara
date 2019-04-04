<?php

use Illuminate\Database\Eloquent\Model;

class IpFailedLogins extends Model
{
    /**
     * Table name for this & extending classes.
     *
     * @var string
     */
    protected $table = 'ip_failed_logins';

    /**
     * Fields names for this & extending classes.
     *
     * @var string
     */
    protected $fillable = ['ip', 'user_email'];

    public $timestamps = false;
}
