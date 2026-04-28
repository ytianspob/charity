<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use think\facade\View;

class Contact extends BaseController
{
    // 联系我们页面
    public function index()
    {
        return View::fetch('contact/index');
    }
}
