<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

class Comment extends Model
{
    // protected $table = 'cp_comment'; // 如需写死表名可取消注释

    public $timestamps = false;
}
