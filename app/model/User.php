<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

class User extends Model
{
    // 如果你设置了表前缀 cp_，且表名是 cp_user，这里可以不用写 table，TP 会自动识别
    // 如果你想写死：
    // protected $table = 'cp_user';

    // 自动时间戳（对应 create_time、update_time）
    protected $autoWriteTimestamp = false; // 如果你用的是 DATETIME 而不是 int 时间戳，保持 false

    // 允许批量写入的字段（可以先不严格限制）
    protected $fillable = [
        'username',
        'password',
        'nickname',
        'role',
        'phone',
        'email',
        'status',
    ];
}
