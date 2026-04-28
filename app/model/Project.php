<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

class Project extends Model
{
    // 如果 database.php 里设置了 prefix = 'cp_'，且表名是 cp_project，这里可以省略 table 设置
    // 如果你想写死表名：
    // protected $table = 'cp_project';

    public $timestamps = false;
}
