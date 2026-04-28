<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

class News extends Model
{
    // 如需写死表名，可取消注释
    // protected $table = 'cp_news';

    public $timestamps = false;
}
