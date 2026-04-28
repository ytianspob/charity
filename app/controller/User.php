<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\User as UserModel;
use app\model\Signup as SignupModel;
use app\model\Project as ProjectModel;
use app\model\Donation as DonationModel;
use think\facade\View;
use think\facade\Request;
use think\facade\Session;

class User extends BaseController
{
    // 注册
    public function register()
    {
        if (Request::isGet()) {
            return View::fetch('user/register');
        }

        if (Request::isPost()) {
            $username         = trim((string)Request::post('username'));
            $password         = (string)Request::post('password');
            $password_confirm = (string)Request::post('password_confirm');
            $nickname         = trim((string)Request::post('nickname')); // 真实姓名/组织名 -> 昵称
            $email            = trim((string)Request::post('email'));    // 邮箱
            $phone            = trim((string)Request::post('phone'));    // 手机号（可选）

            if ($username === '' || $password === '' || $password_confirm === '') {
                Session::flash('auth_msg', '用户名和密码不能为空');
                Session::flash('auth_status', 'error');
                return redirect('/user/register');
            }

            if ($password !== $password_confirm) {
                Session::flash('auth_msg', '两次输入的密码不一致');
                Session::flash('auth_status', 'error');
                return redirect('/user/register');
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Session::flash('auth_msg', '邮箱格式不正确');
                Session::flash('auth_status', 'error');
                return redirect('/user/register');
            }

            // 检查用户名是否已存在
            $exists = UserModel::where('username', $username)->find();
            if ($exists) {
                Session::flash('auth_msg', '该用户名已被注册');
                Session::flash('auth_status', 'error');
                return redirect('/user/register');
            }

            // 密码加密
            $password_hashed = password_hash($password, PASSWORD_DEFAULT);

            // 创建用户
            $user = UserModel::create([
                'username' => $username,
                'password' => $password_hashed,
                'nickname' => $nickname,
                'email'    => $email,
                'phone'    => $phone,
                'role'     => 'user',
                'status'   => 1,
            ]);

            if (!$user) {
                Session::flash('auth_msg', '注册失败，请稍后重试');
                Session::flash('auth_status', 'error');
                return redirect('/user/register');
            }

            // 注册成功：跳转到登录页并提示
            Session::flash('auth_msg', '注册成功，请使用账号登录');
            Session::flash('auth_status', 'success');
            return redirect('/user/login');
        }

        return '不支持的请求方式';
    }

    // 登录
    public function login()
    {
        if (Request::isGet()) {
            return View::fetch('user/login');
        }

        if (Request::isPost()) {
            $username = trim((string)Request::post('username'));
            $password = (string)Request::post('password');

            if ($username === '' || $password === '') {
                Session::flash('auth_msg', '用户名和密码不能为空');
                Session::flash('auth_status', 'error');
                return redirect('/user/login');
            }

            $user = UserModel::where('username', $username)->find();
            if (!$user) {
                Session::flash('auth_msg', '用户不存在');
                Session::flash('auth_status', 'error');
                return redirect('/user/login');
            }

            if (!password_verify($password, $user->password)) {
                Session::flash('auth_msg', '密码错误');
                Session::flash('auth_status', 'error');
                return redirect('/user/login');
            }

            if ((int)$user->status !== 1) {
                Session::flash('auth_msg', '账号已被禁用，请联系管理员');
                Session::flash('auth_status', 'error');
                return redirect('/user/login');
            }

            // 登录成功，写 Session
            Session::set('user_id', $user->id);
            Session::set('username', $user->username);
            Session::set('role', $user->role ?? 'user');

            // 登录前记录过想去的页面
            $redirect = Session::get('login_redirect') ?: '/';
            Session::delete('login_redirect');

            return redirect($redirect);
        }

        return '不支持的请求方式';
    }

    // 退出登录：成功后刷新回上一个页面
    public function logout()
    {
        $referer = Request::header('referer') ?: '/';

        try {
            Session::clear();
        } catch (\Throwable $e) {
            Session::flash('auth_msg', '退出失败，请稍后重试');
            Session::flash('auth_status', 'error');
            return redirect($referer);
        }

        // 如需提示，可打开这两行：
        // Session::flash('auth_msg', '您已退出登录');
        // Session::flash('auth_status', 'success');

        return redirect($referer);
    }

    // 当前登录信息（调试用）
    public function info()
    {
        $userId   = Session::get('user_id');
        $username = Session::get('username');
        $role     = Session::get('role');

        if (!$userId) {
            return '当前未登录';
        }

        return '当前登录用户ID：' . $userId . '，用户名：' . $username . '，角色：' . $role;
    }

    // 我的报名列表
    public function mySignup()
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            Session::set('login_redirect', request()->url());
            Session::flash('auth_msg', '请先登录后再查看报名记录');
            Session::flash('auth_status', 'error');
            return redirect('/user/login');
        }

        $signups = SignupModel::where('user_id', $userId)
            ->order('signup_time', 'desc')
            ->select();

        $data = [];
        foreach ($signups as $s) {
            $project = ProjectModel::find($s->project_id);
            $data[] = [
                'project_title' => $project ? $project->title : '项目已不存在',
                'project_id'    => $s->project_id,
                'status'        => $s->status,
                'signup_time'   => $s->signup_time,
            ];
        }

        View::assign('list', $data);

        return View::fetch('user/my_signup');
    }

    // 我的捐赠记录
    public function myDonation()
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            Session::set('login_redirect', request()->url());
            Session::flash('auth_msg', '请先登录后再查看捐赠记录');
            Session::flash('auth_status', 'error');
            return redirect('/user/login');
        }

        $donations = DonationModel::where('user_id', $userId)
            ->order('donate_time', 'desc')
            ->select();

        $data = [];
        foreach ($donations as $d) {
            $project = ProjectModel::find($d->project_id);
            $data[] = [
                'project_title'  => $project ? $project->title : '项目已不存在',
                'project_id'     => $d->project_id,
                'type'           => $d->type,
                'amount'         => $d->amount,
                'goods_name'     => $d->goods_name,
                'goods_quantity' => $d->goods_quantity,
                'remark'         => $d->remark,
                'donate_time'    => $d->donate_time,
            ];
        }

        View::assign('list', $data);

        return View::fetch('user/my_donation');
    }
}
