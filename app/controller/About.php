<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use think\facade\View;

class About extends BaseController
{
    // 关于我们页面
    public function index()
    {
        return View::fetch('about/index');
    }
}
