<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

class Signup extends Model
{
    // 如果 config/database.php 里 prefix = 'cp_'，且表名 cp_signup，这里可以省略 table
    // protected $table = 'cp_signup';

    public $timestamps = false;
}
