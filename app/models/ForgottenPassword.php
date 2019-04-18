<?php

use Illuminate\Database\Eloquent\Model;

class ForgottenPassword extends Model
{
    /**
     * Table name for this & extending classes.
     *
     * @var string
     */
    protected $table = 'forgotten_passwords';

    /**
     * Fields names for this & extending classes.
     *
     * @var string
     */
    protected $fillable = ['user_id', 'password_token', 'password_last_reset', 'forgotten_password_attempts'];

    public $timestamps = false;
}
