<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\Project as ProjectModel;
use app\model\HelpArticle as HelpModel;
use app\model\Donation as DonationModel;
use app\model\Signup as SignupModel;
use app\model\News as NewsModel;
use think\facade\View;
use think\facade\Session;

class Index extends BaseController
{
    public function index()
    {
        // 当前登录信息
        $userId   = Session::get('user_id');
        $username = Session::get('username');
        $role     = Session::get('role');

        // 最新上线的项目（取 4 条）
        $latestProjects = ProjectModel::where('status', 'online')
            ->order('create_time', 'desc')
            ->limit(4)
            ->select();

        // 最新求助文章（取 4 条）
        $latestHelps = HelpModel::where('status', 'online')
            ->order('create_time', 'desc')
            ->limit(4)
            ->select();

        // 最新新闻（取 4 条）
        $latestNews = NewsModel::where('status', 'online')
            ->order('create_time', 'desc')
            ->limit(4)
            ->select();

        // 简单统计数据
        $totalSignup        = SignupModel::count();
        $totalDonationCount = DonationModel::count();
        $totalDonationMoney = DonationModel::where('type', 'money')->sum('amount');

        // 赋值给视图
        View::assign('userId', $userId);
        View::assign('username', $username);
        View::assign('role', $role);

        View::assign('latestProjects', $latestProjects);
        View::assign('latestHelps', $latestHelps);
        View::assign('latestNews', $latestNews);

        View::assign('totalSignup', $totalSignup);
        View::assign('totalDonationCount', $totalDonationCount);
        View::assign('totalDonationMoney', $totalDonationMoney);

        return View::fetch('index/index');
    }
}
