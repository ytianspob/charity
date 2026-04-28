<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

class Message extends Model
{
    // 表名，如果你表叫 message 或带前缀，这里按实际改：
    protected $table = 'cp_message';

    // 如果主键不是 id，这里也要改；默认就是 id 就不用写
    protected $pk = 'id';

    // 如果你用的是 create_time / update_time 自动时间戳，可以按需配置：
    protected $autoWriteTimestamp = true;
}
