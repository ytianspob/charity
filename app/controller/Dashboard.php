<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use think\facade\Session;

class Dashboard extends BaseController
{
    // 用户中心入口：根据角色跳转
    public function index()
    {
        $userId = Session::get('user_id');
        $role   = Session::get('role');

        if (!$userId) {
            return redirect('/user/login');
        }

        if ($role === 'admin') {
            return redirect('/adminCenter/index');
        } else {
            return redirect('/userCenter/index');
        }
    }
}
