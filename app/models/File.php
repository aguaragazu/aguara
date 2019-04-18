<?php

use Illuminate\Database\Eloquent\Model;

/**
 * File Class.
 *
 * @license    http://opensource.org/licenses/MIT The MIT License (MIT)
 * @author     Omar El Gabry <omar.elgabry.93@gmail.com>
 */
class File extends Model
{
    /**
     * Table name for this & extending classes.
     *
     * @var string
     */
    protected $table = 'files';

    /**
     * Fields names for this & extending classes.
     *
     * @var string
     */
    protected $fillable = ['user_id', 'filename', 'extension', 'date'];

    public $timestamps = false;
}
