<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

class Donation extends Model
{
    // 如果 config/database.php 里设置了 prefix = 'cp_'，且表名 cp_donation，可以省略 table
    // 如果你想写死表名，可以取消下面注释：
    // protected $table = 'cp_donation';

    public $timestamps = false;
}
