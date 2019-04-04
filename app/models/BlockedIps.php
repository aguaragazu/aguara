<?php

use Illuminate\Database\Eloquent\Model;

class BlockedIps extends Model
{
    /**
     * Table name for this & extending classes.
     *
     * @var string
     */
    protected $table = 'blocked_ips';

    /**
     * Fields names for this & extending classes.
     *
     * @var string
     */
    protected $fillable = ['ip'];

    public $timestamps = false;
}
