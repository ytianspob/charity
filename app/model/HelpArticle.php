<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

class HelpArticle extends Model
{
    // 如果 config/database.php 中 prefix = 'cp_'，且表名 cp_help_article，可以不写 table
    // 如需写死表名可取消注释：
    // protected $table = 'cp_help_article';

    public $timestamps = false;
}
